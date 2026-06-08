using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Options;
using SwimShady.Api.Data;
using SwimShady.Api.Models;

namespace SwimShady.Api.Services;

/// <summary>
/// IP logging + Fail2Ban. Records login/API outcomes, auto-bans abusive IPs (N failures
/// in a window), and answers "is this IP blocked?" — an admin whitelist (Allow) always wins.
/// Scoped: uses the request's <see cref="AppDbContext"/>.
/// </summary>
public class IpSecurityService
{
    private readonly AppDbContext _db;
    private readonly AppSettings _opt;

    public IpSecurityService(AppDbContext db, IOptions<AppSettings> opt)
    {
        _db = db;
        _opt = opt.Value;
    }

    /// <summary>
    /// Real client IP. Azure App Service forwards it in X-Forwarded-For (client first,
    /// sometimes with a :port). Prefer that; fall back to the connection's remote IP.
    /// </summary>
    public static string GetClientIp(HttpContext ctx)
    {
        var xff = ctx.Request.Headers["X-Forwarded-For"].ToString();
        if (!string.IsNullOrWhiteSpace(xff))
        {
            var first = xff.Split(',')[0].Trim();
            if (first.StartsWith('['))                       // [ipv6]:port
            {
                var end = first.IndexOf(']');
                if (end > 1) return first[1..end];
            }
            else if (first.Count(c => c == ':') == 1)        // ipv4:port
            {
                first = first[..first.IndexOf(':')];
            }
            if (!string.IsNullOrWhiteSpace(first)) return first;
        }
        return ctx.Connection.RemoteIpAddress?.ToString() ?? "unknown";
    }

    /// <summary>True if the IP has an active Deny and no active Allow (whitelist wins).</summary>
    public async Task<bool> IsBlockedAsync(string ip, CancellationToken ct = default)
    {
        var now = DateTime.UtcNow;
        var rules = await _db.IpRules
            .Where(r => r.Ip == ip && (r.ExpiresAt == null || r.ExpiresAt > now))
            .ToListAsync(ct);
        if (rules.Any(r => r.Kind == IpRuleKind.Allow)) return false;
        return rules.Any(r => r.Kind == IpRuleKind.Deny);
    }

    /// <summary>Log a login attempt; auto-ban on repeated failures in the window.</summary>
    public async Task RecordLoginAsync(string ip, string? username, bool success, CancellationToken ct = default)
    {
        _db.LoginAttempts.Add(new LoginAttempt { Ip = ip, Username = Trunc(username, 64), Success = success });
        await _db.SaveChangesAsync(ct);

        if (success) return;
        var window = DateTime.UtcNow.AddSeconds(-_opt.BanWindowSeconds);
        var fails = await _db.LoginAttempts.CountAsync(
            l => l.Ip == ip && !l.Success && l.CreatedAt >= window, ct);
        if (fails >= _opt.BanThreshold)
            await AutoBanAsync(ip, $"{fails} failed logins in {_opt.BanWindowSeconds}s", ct);
    }

    /// <summary>
    /// Log an API request. When <paramref name="countFailure"/> is set, a 401/403 counts
    /// toward the API Fail2Ban window (and may auto-ban). Never throws to the caller's path.
    /// </summary>
    public async Task RecordApiRequestAsync(string ip, string method, string path, int status, int? userId, bool countFailure, CancellationToken ct = default)
    {
        _db.ApiRequests.Add(new ApiRequest
        {
            Ip = ip,
            Method = Trunc(method, 8) ?? "",
            Path = Trunc(path, 256) ?? "",
            StatusCode = status,
            UserId = userId,
        });
        await _db.SaveChangesAsync(ct);

        if (!countFailure || (status != 401 && status != 403)) return;
        var window = DateTime.UtcNow.AddSeconds(-_opt.BanWindowSeconds);
        var fails = await _db.ApiRequests.CountAsync(
            a => a.Ip == ip && (a.StatusCode == 401 || a.StatusCode == 403) && a.CreatedAt >= window, ct);
        if (fails >= _opt.BanThreshold)
            await AutoBanAsync(ip, $"{fails} auth failures in {_opt.BanWindowSeconds}s", ct);
    }

    private async Task AutoBanAsync(string ip, string reason, CancellationToken ct)
    {
        var now = DateTime.UtcNow;
        var rules = await _db.IpRules.Where(r => r.Ip == ip).ToListAsync(ct);
        if (rules.Any(r => r.Kind == IpRuleKind.Allow)) return;                 // whitelisted — never ban
        if (rules.Any(r => r.Kind == IpRuleKind.Deny && r.Source == IpRuleSource.Auto
                           && (r.ExpiresAt == null || r.ExpiresAt > now))) return;  // already auto-banned

        _db.IpRules.Add(new IpRule
        {
            Ip = ip,
            Kind = IpRuleKind.Deny,
            Source = IpRuleSource.Auto,
            Reason = Trunc(reason, 255),
            ExpiresAt = now.AddMinutes(_opt.BanDurationMinutes),
        });
        await _db.SaveChangesAsync(ct);
    }

    private static string? Trunc(string? s, int max) =>
        string.IsNullOrEmpty(s) ? s : (s.Length <= max ? s : s[..max]);
}
