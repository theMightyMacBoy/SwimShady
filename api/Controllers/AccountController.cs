using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Identity;
using Microsoft.AspNetCore.Mvc;
using SwimShady.Api.Data;
using SwimShady.Api.Dtos;
using SwimShady.Api.Models;

namespace SwimShady.Api.Controllers;

[Route("api/account")]
[Authorize]
public class AccountController : ApiControllerBase
{
    private readonly AppDbContext _db;
    private readonly IPasswordHasher<User> _hasher;

    public AccountController(AppDbContext db, IPasswordHasher<User> hasher)
    {
        _db = db;
        _hasher = hasher;
    }

    [HttpPost("password")]
    public async Task<IActionResult> ChangePassword([FromBody] ChangePasswordRequest req)
    {
        var user = await _db.Users.FindAsync(UserId);
        if (user is null)
            return Unauthorized();

        // The demo account is public (creds published) — don't let visitors lock it.
        if (user.Username == "demo")
            return Forbidden("the demo account password cannot be changed");

        if (_hasher.VerifyHashedPassword(user, user.PasswordHash, req.Current) == PasswordVerificationResult.Failed)
            return BadRequest(new { error = "current password is incorrect" });

        if ((req.New?.Length ?? 0) < 8)
            return BadRequest(new { error = "new password must be at least 8 characters" });

        user.PasswordHash = _hasher.HashPassword(user, req.New!);
        await _db.SaveChangesAsync();
        return Ok(new { ok = true });
    }
}
