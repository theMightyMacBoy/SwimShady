using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Identity;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;
using SwimShady.Api.Data;
using SwimShady.Api.Dtos;
using SwimShady.Api.Models;

namespace SwimShady.Api.Controllers;

[Route("api/users")]
[Authorize(Roles = Roles.Admin)]
public class UsersController : ApiControllerBase
{
    private readonly AppDbContext _db;
    private readonly IPasswordHasher<User> _hasher;

    public UsersController(AppDbContext db, IPasswordHasher<User> hasher)
    {
        _db = db;
        _hasher = hasher;
    }

    [HttpGet]
    public async Task<ActionResult<IEnumerable<UserDto>>> List()
    {
        var users = await _db.Users.Include(u => u.UserDevices).OrderBy(u => u.Username).ToListAsync();
        return Ok(users.Select(ToDto));
    }

    [HttpPost]
    public async Task<ActionResult<UserDto>> Create([FromBody] CreateUserRequest req)
    {
        if (string.IsNullOrWhiteSpace(req.Username) || !Roles.All.Contains(req.Role))
            return BadRequest(new { error = "username and a valid role are required" });
        if ((req.Password?.Length ?? 0) < 8)
            return BadRequest(new { error = "password must be at least 8 characters" });
        if (await _db.Users.AnyAsync(u => u.Username == req.Username))
            return Conflict(new { error = "that username already exists" });

        var user = new User { Username = req.Username.Trim(), Role = req.Role };
        user.PasswordHash = _hasher.HashPassword(user, req.Password!);
        _db.Users.Add(user);
        await _db.SaveChangesAsync();

        if (req.Role != Roles.Admin && req.DeviceIds is { Length: > 0 })
            await SetDevices(user.Id, req.DeviceIds);

        return await One(user.Id);
    }

    [HttpPut("{id:int}")]
    public async Task<ActionResult<UserDto>> Update(int id, [FromBody] UpdateUserRequest req)
    {
        var user = await _db.Users.FindAsync(id);
        if (user is null) return NotFound();
        if (!Roles.All.Contains(req.Role)) return BadRequest(new { error = "invalid role" });

        if (user.Role == Roles.Admin && req.Role != Roles.Admin && await AdminCount() <= 1)
            return BadRequest(new { error = "cannot demote the last admin" });

        if (!string.IsNullOrEmpty(req.Password))
        {
            if (req.Password.Length < 8)
                return BadRequest(new { error = "new password must be at least 8 characters" });
            user.PasswordHash = _hasher.HashPassword(user, req.Password);
        }

        user.Role = req.Role;
        await _db.SaveChangesAsync();
        await SetDevices(id, req.Role == Roles.Admin ? Array.Empty<int>() : (req.DeviceIds ?? Array.Empty<int>()));
        return await One(id);
    }

    [HttpDelete("{id:int}")]
    public async Task<IActionResult> Delete(int id)
    {
        var user = await _db.Users.FindAsync(id);
        if (user is null) return NotFound();
        if (id == UserId) return BadRequest(new { error = "you cannot delete your own account" });
        if (user.Role == Roles.Admin && await AdminCount() <= 1)
            return BadRequest(new { error = "cannot delete the last admin" });

        _db.Users.Remove(user);
        await _db.SaveChangesAsync();
        return NoContent();
    }

    private async Task<int> AdminCount() => await _db.Users.CountAsync(u => u.Role == Roles.Admin);

    private async Task SetDevices(int userId, int[] deviceIds)
    {
        var existing = _db.UserDevices.Where(ud => ud.UserId == userId);
        _db.UserDevices.RemoveRange(existing);
        foreach (var did in deviceIds.Distinct())
            _db.UserDevices.Add(new UserDevice { UserId = userId, DeviceId = did });
        await _db.SaveChangesAsync();
    }

    private async Task<ActionResult<UserDto>> One(int id)
    {
        var u = await _db.Users.Include(x => x.UserDevices).FirstAsync(x => x.Id == id);
        return Ok(ToDto(u));
    }

    private static UserDto ToDto(User u) =>
        new(u.Id, u.Username, u.Role, u.UserDevices.Select(ud => ud.DeviceId).ToList(), u.LastLoginAt);
}
