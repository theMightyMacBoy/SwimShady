using System.Text.RegularExpressions;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Options;
using SwimShady.Api.Data;
using SwimShady.Api.Dtos;
using SwimShady.Api.Models;
using SwimShady.Api.Services;

namespace SwimShady.Api.Controllers;

[Route("api/devices")]
[Authorize]
public class DevicesController : ApiControllerBase
{
    private readonly AppDbContext _db;
    private readonly AppSettings _opt;

    public DevicesController(AppDbContext db, IOptions<AppSettings> opt)
    {
        _db = db;
        _opt = opt.Value;
    }

    private bool Online(DateTime? lastSeen) =>
        lastSeen.HasValue && (DateTime.UtcNow - lastSeen.Value).TotalSeconds <= _opt.OfflineAfterSeconds;

    private async Task<bool> CanAccess(int deviceId) =>
        IsAdmin || await _db.UserDevices.AnyAsync(ud => ud.UserId == UserId && ud.DeviceId == deviceId);

    [HttpGet]
    public async Task<ActionResult<IEnumerable<DeviceDto>>> List()
    {
        var q = _db.Devices.AsQueryable();
        if (!IsAdmin)
            q = q.Where(d => d.UserDevices.Any(ud => ud.UserId == UserId));
        var devices = await q.OrderBy(d => d.Name).ToListAsync();
        return Ok(devices.Select(d => new DeviceDto(d.Id, d.Uid, d.Name, Online(d.LastSeenAt), d.LastSeenAt, d.FirmwareVersion, d.Channel)));
    }

    [HttpPost]
    [Authorize(Roles = Roles.Admin)]
    public async Task<ActionResult<CreateDeviceResponse>> Create([FromBody] CreateDeviceRequest req)
    {
        var uid = (req.Uid ?? "").Trim().ToLowerInvariant();
        if (!Regex.IsMatch(uid, "^[a-z0-9][a-z0-9-]{1,63}$"))
            return BadRequest(new { error = "uid must be lowercase letters, numbers, and hyphens" });
        if (string.IsNullOrWhiteSpace(req.Name))
            return BadRequest(new { error = "name is required" });
        if (await _db.Devices.AnyAsync(d => d.Uid == uid))
            return Conflict(new { error = "a device with that uid already exists" });

        var token = Tokens.NewDeviceToken();
        var device = new Device { Uid = uid, Name = req.Name.Trim(), TokenHash = Tokens.Hash(token) };
        _db.Devices.Add(device);
        await _db.SaveChangesAsync();

        return Ok(new CreateDeviceResponse(device.Id, device.Uid, device.Name, token));
    }

    [HttpPost("{id:int}/regenerate-token")]
    [Authorize(Roles = Roles.Admin)]
    public async Task<IActionResult> RegenerateToken(int id)
    {
        var device = await _db.Devices.FindAsync(id);
        if (device is null) return NotFound();

        var token = Tokens.NewDeviceToken();
        device.TokenHash = Tokens.Hash(token);
        await _db.SaveChangesAsync();
        return Ok(new { token });
    }

    [HttpDelete("{id:int}")]
    [Authorize(Roles = Roles.Admin)]
    public async Task<IActionResult> Delete(int id)
    {
        var device = await _db.Devices.FindAsync(id);
        if (device is null) return NotFound();
        _db.Devices.Remove(device);
        await _db.SaveChangesAsync();
        return NoContent();
    }

    [HttpPost("{id:int}/channel")]
    [Authorize(Roles = Roles.Admin)]
    public async Task<IActionResult> SetChannel(int id, [FromBody] SetChannelRequest req)
    {
        if (!Channels.All.Contains(req.Channel))
            return BadRequest(new { error = "invalid channel" });
        var device = await _db.Devices.FindAsync(id);
        if (device is null) return NotFound();
        device.Channel = req.Channel;
        await _db.SaveChangesAsync();
        return Ok(new { ok = true });
    }

    [HttpGet("{id:int}/state")]
    public async Task<ActionResult<StateDto>> State(int id)
    {
        if (!await CanAccess(id)) return Forbidden("no access to this device");
        var device = await _db.Devices.Include(d => d.State).FirstOrDefaultAsync(d => d.Id == id);
        if (device is null) return NotFound();

        var s = device.State;
        string? display = s?.WaterTemp is double w ? $"{w:0.#} °F" : null;
        var pending = await _db.Commands.CountAsync(c => c.DeviceId == id && c.Status == CommandStatus.Pending);

        return Ok(new StateDto(
            Online(device.LastSeenAt), device.Id, device.Name,
            s?.WaterTemp, display, s?.TargetTemp, s?.SystemOn, s?.PumpOn,
            s?.PumpStatus, s?.CooldownRemaining, s?.HeaterOn, s?.HeaterStatus, s?.ReportedAt,
            pending, DateTime.UtcNow.ToString("o")));
    }

    [HttpPost("{id:int}/commands")]
    public async Task<IActionResult> QueueCommand(int id, [FromBody] CommandRequest req)
    {
        if (!await CanAccess(id)) return Forbidden("no access to this device");
        if (!Roles.CanControl(Role)) return Forbidden("your account is read-only");
        if (!await _db.Devices.AnyAsync(d => d.Id == id)) return NotFound();

        string action;
        string? value;
        try
        {
            (action, value) = Services.Commands.Normalize(req.Action ?? "", req.Value);
        }
        catch (ArgumentException ex)
        {
            return BadRequest(new { error = ex.Message });
        }

        var cmd = new CommandQueueItem { DeviceId = id, Action = action, Value = value, CreatedBy = UserId };
        _db.Commands.Add(cmd);
        await _db.SaveChangesAsync();
        return Ok(new { ok = true, commandId = cmd.Id });
    }

    [HttpGet("{id:int}/history")]
    public async Task<ActionResult<HistoryResponse>> History(int id, [FromQuery] int hours = 24)
    {
        if (!await CanAccess(id)) return Forbidden("no access to this device");
        hours = Math.Clamp(hours, 1, 24 * 30);
        var cutoff = DateTime.UtcNow.AddHours(-hours);
        var points = await _db.History
            .Where(h => h.DeviceId == id && h.RecordedAt >= cutoff)
            .OrderBy(h => h.RecordedAt)
            .Select(h => new HistoryPointDto(h.RecordedAt, h.WaterTemp, h.TargetTemp, h.HeaterOn))
            .ToListAsync();
        return Ok(new HistoryResponse(hours, id, points));
    }
}
