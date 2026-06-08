using Microsoft.AspNetCore.Identity;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;
using SwimShady.Api.Data;
using SwimShady.Api.Dtos;
using SwimShady.Api.Models;
using SwimShady.Api.Services;

namespace SwimShady.Api.Controllers;

[ApiController]
[Route("api/auth")]
public class AuthController : ControllerBase
{
    private readonly AppDbContext _db;
    private readonly IPasswordHasher<User> _hasher;
    private readonly JwtTokenService _jwt;
    private readonly IpSecurityService _sec;

    public AuthController(AppDbContext db, IPasswordHasher<User> hasher, JwtTokenService jwt, IpSecurityService sec)
    {
        _db = db;
        _hasher = hasher;
        _jwt = jwt;
        _sec = sec;
    }

    [HttpPost("login")]
    public async Task<ActionResult<LoginResponse>> Login([FromBody] LoginRequest req)
    {
        var ip = IpSecurityService.GetClientIp(HttpContext);

        var user = await _db.Users.FirstOrDefaultAsync(u => u.Username == req.Username);
        if (user is null)
        {
            await _sec.RecordLoginAsync(ip, req.Username, success: false);
            return Unauthorized(new { error = "invalid username or password" });
        }

        var result = _hasher.VerifyHashedPassword(user, user.PasswordHash, req.Password);
        if (result == PasswordVerificationResult.Failed)
        {
            await _sec.RecordLoginAsync(ip, req.Username, success: false);
            return Unauthorized(new { error = "invalid username or password" });
        }

        if (result == PasswordVerificationResult.SuccessRehashNeeded)
            user.PasswordHash = _hasher.HashPassword(user, req.Password);

        user.LastLoginAt = DateTime.UtcNow;
        await _db.SaveChangesAsync();
        await _sec.RecordLoginAsync(ip, req.Username, success: true);

        var (token, expires) = _jwt.Create(user);
        return Ok(new LoginResponse(token, user.Username, user.Role, expires));
    }
}
