using System.Security.Claims;
using SwimShady.Api.Services;

namespace SwimShady.Api.Middleware;

/// <summary>
/// Runs before authentication. For every /api request: blocks banned IPs (403),
/// then logs the request and feeds 401/403 outcomes into the API Fail2Ban counter.
/// Applies to ALL /api paths including the device endpoints (per design).
/// </summary>
public class IpGuardMiddleware
{
    private readonly RequestDelegate _next;

    public IpGuardMiddleware(RequestDelegate next) => _next = next;

    public async Task InvokeAsync(HttpContext ctx)
    {
        var path = ctx.Request.Path.Value ?? "";
        if (!path.StartsWith("/api", StringComparison.OrdinalIgnoreCase))
        {
            await _next(ctx);
            return;
        }

        var sec = ctx.RequestServices.GetRequiredService<IpSecurityService>();
        var ip = IpSecurityService.GetClientIp(ctx);

        if (await sec.IsBlockedAsync(ip, ctx.RequestAborted))
        {
            ctx.Response.StatusCode = StatusCodes.Status403Forbidden;
            ctx.Response.ContentType = "application/json";
            await ctx.Response.WriteAsync("{\"error\":\"your IP is temporarily blocked\"}", ctx.RequestAborted);
            await Record(sec, ip, ctx, StatusCodes.Status403Forbidden, countFailure: false); // already banned — don't re-count
            return;
        }

        await _next(ctx);

        // The login endpoint runs its own (username-aware) Fail2Ban, so don't double-count its 401s.
        var isLogin = path.StartsWith("/api/auth/login", StringComparison.OrdinalIgnoreCase);
        await Record(sec, ip, ctx, ctx.Response.StatusCode, countFailure: !isLogin);
    }

    private static async Task Record(IpSecurityService sec, string ip, HttpContext ctx, int status, bool countFailure)
    {
        try
        {
            int? userId = null;
            if (int.TryParse(ctx.User?.FindFirstValue(ClaimTypes.NameIdentifier), out var uid)) userId = uid;
            // Use None: the response is done; finish logging even if the client disconnected.
            await sec.RecordApiRequestAsync(ip, ctx.Request.Method, ctx.Request.Path.Value ?? "", status, userId, countFailure, CancellationToken.None);
        }
        catch
        {
            // Logging/ban bookkeeping must never break or fail a request.
        }
    }
}
