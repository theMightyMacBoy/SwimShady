using System.Net;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;
using SwimShady.Api.Data;
using SwimShady.Api.Dtos;
using SwimShady.Api.Models;

namespace SwimShady.Api.Controllers;

[Route("api/admin/ips")]
[Authorize(Roles = Roles.Admin)]
public class IpAdminController : ApiControllerBase
{
    private readonly AppDbContext _db;

    public IpAdminController(AppDbContext db) => _db = db;

    /// <summary>Whitelist / block / auto-ban records (most recent first).</summary>
    [HttpGet("rules")]
    public async Task<ActionResult<IEnumerable<IpRuleDto>>> Rules()
    {
        var rules = await _db.IpRules.OrderByDescending(r => r.CreatedAt).Take(500).ToListAsync();
        return Ok(rules.Select(ToDto));
    }

    /// <summary>Add a permanent manual whitelist (allow) or block (deny).</summary>
    [HttpPost("rules")]
    public async Task<ActionResult<IpRuleDto>> AddRule([FromBody] CreateIpRuleRequest req)
    {
        var ip = (req.Ip ?? "").Trim();
        if (!IPAddress.TryParse(ip, out _))
            return BadRequest(new { error = "invalid IP address" });

        IpRuleKind kind;
        switch ((req.Kind ?? "").ToLowerInvariant())
        {
            case "allow": kind = IpRuleKind.Allow; break;
            case "deny": kind = IpRuleKind.Deny; break;
            default: return BadRequest(new { error = "kind must be 'allow' or 'deny'" });
        }

        var rule = new IpRule
        {
            Ip = ip,
            Kind = kind,
            Source = IpRuleSource.Manual,   // manual rules are permanent and never auto-pruned
            Reason = Trunc(req.Note, 255),
            ExpiresAt = null,
            CreatedBy = UserId,
        };
        _db.IpRules.Add(rule);
        await _db.SaveChangesAsync();
        return Ok(ToDto(rule));
    }

    /// <summary>Remove any rule by id — unban, un-whitelist, or un-block.</summary>
    [HttpDelete("rules/{id:int}")]
    public async Task<IActionResult> DeleteRule(int id)
    {
        var rule = await _db.IpRules.FindAsync(id);
        if (rule is null) return NotFound();
        _db.IpRules.Remove(rule);
        await _db.SaveChangesAsync();
        return NoContent();
    }

    [HttpGet("logins")]
    public async Task<ActionResult<IEnumerable<LoginAttemptDto>>> Logins([FromQuery] int hours = 24, [FromQuery] string? ip = null)
    {
        var cutoff = DateTime.UtcNow.AddHours(-Math.Clamp(hours, 1, 24 * 30));
        var q = _db.LoginAttempts.Where(l => l.CreatedAt >= cutoff);
        if (!string.IsNullOrWhiteSpace(ip)) q = q.Where(l => l.Ip == ip);
        var rows = await q.OrderByDescending(l => l.CreatedAt).Take(500).ToListAsync();
        return Ok(rows.Select(l => new LoginAttemptDto(l.Id, l.Ip, l.Username, l.Success, l.CreatedAt)));
    }

    [HttpGet("requests")]
    public async Task<ActionResult<IEnumerable<ApiRequestLogDto>>> Requests([FromQuery] int hours = 24, [FromQuery] string? ip = null)
    {
        var cutoff = DateTime.UtcNow.AddHours(-Math.Clamp(hours, 1, 24 * 30));
        var q = _db.ApiRequests.Where(a => a.CreatedAt >= cutoff);
        if (!string.IsNullOrWhiteSpace(ip)) q = q.Where(a => a.Ip == ip);
        var rows = await q.OrderByDescending(a => a.CreatedAt).Take(500).ToListAsync();
        return Ok(rows.Select(a => new ApiRequestLogDto(a.Id, a.Ip, a.Method, a.Path, a.StatusCode, a.UserId, a.CreatedAt)));
    }

    private static string? Trunc(string? s, int max) =>
        string.IsNullOrEmpty(s) ? s : (s.Length <= max ? s : s[..max]);

    private static IpRuleDto ToDto(IpRule r) => new(
        r.Id, r.Ip, r.Kind.ToString().ToLowerInvariant(), r.Source.ToString().ToLowerInvariant(),
        r.Reason, r.ExpiresAt, r.CreatedAt);
}
