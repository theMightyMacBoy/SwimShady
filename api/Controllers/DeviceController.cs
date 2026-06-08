using System.Text.Json;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Options;
using SwimShady.Api.Data;
using SwimShady.Api.Dtos;
using SwimShady.Api.Models;
using SwimShady.Api.Services;

namespace SwimShady.Api.Controllers;

/// <summary>Device-facing endpoint. The ESP32 (behind NAT) polls this; token auth, no JWT.</summary>
[ApiController]
[Route("api/device")]
public class DeviceController : ControllerBase
{
    private readonly AppDbContext _db;
    private readonly AppSettings _opt;

    public DeviceController(AppDbContext db, IOptions<AppSettings> opt)
    {
        _db = db;
        _opt = opt.Value;
    }

    // Web defaults = camelCase, matching what the firmware parses (commands/action/value/id).
    private static readonly JsonSerializerOptions JsonOpts = new(JsonSerializerDefaults.Web);

    [HttpPost("heartbeat")]
    public async Task<IActionResult> Heartbeat([FromBody] HeartbeatRequest req)
    {
        var token = Request.Headers["X-Device-Token"].ToString();
        if (string.IsNullOrEmpty(token))
            return Unauthorized(new { error = "unauthorized" });

        var hash = Tokens.Hash(token);
        var device = await _db.Devices.Include(d => d.State).FirstOrDefaultAsync(d => d.TokenHash == hash);
        if (device is null)
            return Unauthorized(new { error = "unauthorized" });

        var now = DateTime.UtcNow;
        device.LastSeenAt = now;
        device.FirmwareVersion = Trunc(req.FirmwareVersion, 64);

        // Upsert latest state.
        var state = device.State ?? new DeviceState { DeviceId = device.Id };
        state.WaterTemp = req.WaterTemp;
        state.TargetTemp = req.TargetTemp;
        state.PumpOn = req.PumpOn;
        state.SystemOn = req.SystemOn;
        state.HeaterOn = req.HeaterOn;
        state.HeaterStatus = Trunc(req.HeaterStatus, 32);
        state.PumpStatus = Trunc(req.PumpStatus, 16);
        state.CooldownRemaining = Trunc(req.CooldownRemaining, 8);
        state.ReportedAt = now;
        if (device.State is null)
            _db.DeviceStates.Add(state);

        // Throttled history (one row per device per HistoryIntervalSeconds).
        var cutoff = now.AddSeconds(-_opt.HistoryIntervalSeconds);
        var recent = await _db.History.AnyAsync(h => h.DeviceId == device.Id && h.RecordedAt >= cutoff);
        if (!recent)
        {
            _db.History.Add(new HistoryEntry
            {
                DeviceId = device.Id,
                RecordedAt = now,
                WaterTemp = req.WaterTemp,
                TargetTemp = req.TargetTemp,
                PumpOn = req.PumpOn,
                SystemOn = req.SystemOn,
                HeaterOn = req.HeaterOn,
                PumpStatus = Trunc(req.PumpStatus, 16),
            });
        }

        // Pull pending commands (at-most-once: marked done as handed out).
        var pending = await _db.Commands
            .Where(c => c.DeviceId == device.Id && c.Status == CommandStatus.Pending)
            .OrderBy(c => c.Id)
            .ToListAsync();
        foreach (var c in pending)
        {
            c.Status = CommandStatus.Done;
            c.DeliveredAt = now;
        }

        await _db.SaveChangesAsync();

        var cmds = pending.Select(c => new CommandDto(c.Id, c.Action, c.Value)).ToList();

        // Offer a firmware update if the latest uploaded build differs from what the
        // device is running. URLs carry the device token (auth for the download).
        FirmwareUpdateDto? update = null;
        if (!string.IsNullOrEmpty(req.FirmwareVersion))
        {
            var latest = await _db.Firmware.Where(f => f.Channel == device.Channel)
                .OrderByDescending(f => f.Id)
                .Select(f => f.Version).FirstOrDefaultAsync();
            if (latest != null && latest != req.FirmwareVersion)
            {
                var t = Uri.EscapeDataString(token);
                var baseUrl = $"{Request.Scheme}://{Request.Host}/api/device/firmware";
                update = new FirmwareUpdateDto(latest, $"{baseUrl}/bin?token={t}", $"{baseUrl}/md5?token={t}");
            }
        }

        // Write with an explicit Content-Length. ASP.NET Core otherwise streams JSON as
        // chunked transfer-encoding, which the ESP32's esp-idf HTTP client can't read
        // (it requires Content-Length). This is the device's only endpoint, so scope it here.
        var bytes = JsonSerializer.SerializeToUtf8Bytes(
            new HeartbeatResponse(true, cmds, update, now.ToString("o")), JsonOpts);
        Response.ContentType = "application/json";
        Response.ContentLength = bytes.Length;
        await Response.Body.WriteAsync(bytes);
        return new EmptyResult();
    }

    /// <summary>
    /// Last known desired state, so the device can resume after a reboot/power loss.
    /// Token auth (no JWT). Explicit Content-Length like the heartbeat (esp-idf client needs it).
    /// </summary>
    [HttpGet("state")]
    public async Task<IActionResult> State()
    {
        var token = Request.Headers["X-Device-Token"].ToString();
        if (string.IsNullOrEmpty(token))
            return Unauthorized(new { error = "unauthorized" });

        var hash = Tokens.Hash(token);
        var device = await _db.Devices.Include(d => d.State).FirstOrDefaultAsync(d => d.TokenHash == hash);
        if (device is null)
            return Unauthorized(new { error = "unauthorized" });

        var s = device.State;
        var bytes = JsonSerializer.SerializeToUtf8Bytes(
            new DeviceStateResponse(s is not null, s?.TargetTemp, s?.SystemOn, s?.PumpOn), JsonOpts);
        Response.ContentType = "application/json";
        Response.ContentLength = bytes.Length;
        await Response.Body.WriteAsync(bytes);
        return new EmptyResult();
    }

    private static string? Trunc(string? s, int n) => s is null ? null : (s.Length <= n ? s : s[..n]);

    // --- Device-facing firmware download (token in query; the OTA client can't send headers) ---

    [HttpGet("firmware/bin")]
    public async Task<IActionResult> FirmwareBin([FromQuery] string? token)
    {
        var device = await DeviceByToken(token);
        if (device is null) return Unauthorized();
        var fw = await _db.Firmware.Where(f => f.Channel == device.Channel)
            .OrderByDescending(f => f.Id).FirstOrDefaultAsync();
        if (fw is null) return NotFound();
        return File(fw.Data, "application/octet-stream");  // sets Content-Length
    }

    [HttpGet("firmware/md5")]
    public async Task<IActionResult> FirmwareMd5([FromQuery] string? token)
    {
        var device = await DeviceByToken(token);
        if (device is null) return Unauthorized();
        var md5 = await _db.Firmware.Where(f => f.Channel == device.Channel)
            .OrderByDescending(f => f.Id).Select(f => f.Md5).FirstOrDefaultAsync();
        if (md5 is null) return NotFound();
        return Content(md5, "text/plain");
    }

    private async Task<Device?> DeviceByToken(string? token)
    {
        if (string.IsNullOrEmpty(token)) return null;
        var hash = Tokens.Hash(token);
        return await _db.Devices.FirstOrDefaultAsync(d => d.TokenHash == hash);
    }
}
