using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Options;
using SwimShady.Api.Data;
using SwimShady.Api.Models;

namespace SwimShady.Api.Services;

/// <summary>
/// Drives the public "Demo Pool" device like a virtual ESP32: every couple seconds it
/// drains the device's queued commands and runs a simplified copy of the firmware control
/// logic (heater gating, 2:30 anti-short-cycle lockout, 5-minute cooldown), writing the
/// result to the normal DeviceState/History tables so the existing UI shows it live.
/// In-memory timers reset if the app cold-starts (fine for a demo).
/// </summary>
public class DemoSimulatorService : BackgroundService
{
    private const int TickSeconds = 1;
    private const double Baseline = 75.0;         // °F resting water temp (heater off, settled)
    private const int HeatStepSeconds = 5;        // +1 °F every 5s while heating toward target
    private const int CoolStepSeconds = 10;       // -1 °F every 10s while cooling toward baseline
    private const int LockoutSeconds = 150;       // 2:30 anti-short-cycle
    private const int CooldownSeconds = 300;      // 5-minute fireman's cooldown

    private readonly IServiceProvider _sp;
    private readonly AppSettings _opt;

    // In-memory sim timers (the visible snapshot lives in DeviceState).
    private DateTime? _heaterLastOff;
    private DateTime _cooldownDeadline = DateTime.MinValue;
    private bool _parked;
    private DateTime _lastHistory = DateTime.MinValue;
    private DateTime _lastTempStep = DateTime.MinValue;

    public DemoSimulatorService(IServiceProvider sp, IOptions<AppSettings> opt)
    {
        _sp = sp;
        _opt = opt.Value;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        while (!stoppingToken.IsCancellationRequested)
        {
            try { await TickAsync(stoppingToken); }
            catch { /* best-effort; resume next tick */ }
            await Task.Delay(TimeSpan.FromSeconds(TickSeconds), stoppingToken);
        }
    }

    private async Task TickAsync(CancellationToken ct)
    {
        using var scope = _sp.CreateScope();
        var db = scope.ServiceProvider.GetRequiredService<AppDbContext>();

        var device = await db.Devices.Include(d => d.State).FirstOrDefaultAsync(d => d.IsDemo, ct);
        if (device?.State is null) return;
        var s = device.State;
        var now = DateTime.UtcNow;

        // Working state seeded from the persisted snapshot.
        double temp = s.WaterTemp ?? Baseline;
        int target = s.TargetTemp ?? 82;
        bool systemOn = s.SystemOn ?? false;
        bool pumpOn = s.PumpOn ?? false;
        bool heaterOn = s.HeaterOn ?? false;

        // Heater off + establish cooldown debt (mirrors firmware shutdown_with_cooldown).
        void Shutdown()
        {
            if (heaterOn) { heaterOn = false; _heaterLastOff = now; }
            double sinceOff = _heaterLastOff.HasValue ? (now - _heaterLastOff.Value).TotalSeconds : CooldownSeconds;
            double needed = Math.Max(0, CooldownSeconds - sinceOff);
            var deadline = now.AddSeconds(needed);
            if (deadline > _cooldownDeadline) _cooldownDeadline = deadline;
            if (now < _cooldownDeadline) _parked = true;        // pump keeps running, "Cooling Down"
            else { _parked = false; pumpOn = false; }
        }

        // Apply queued commands (the demo user controls the sim through the normal command queue).
        var pending = await db.Commands
            .Where(c => c.DeviceId == device.Id && c.Status == CommandStatus.Pending)
            .OrderBy(c => c.Id).ToListAsync(ct);
        foreach (var c in pending)
        {
            switch (c.Action)
            {
                case "set_target":
                    if (int.TryParse(c.Value, out var t)) target = Math.Clamp(t, 70, 104);
                    break;
                case "system_on": systemOn = true; break;          // heating enable only — does NOT start the pump
                case "system_off":                                  // disable heating; pump keeps running, no cool-down
                    systemOn = false;
                    if (heaterOn) { heaterOn = false; _heaterLastOff = now; }
                    break;
                case "pump_on": pumpOn = true; _parked = false; break;
                case "pump_off": Shutdown(); break;                 // stopping circulation triggers the 5-min cool-down
                case "cancel_cooldown": _parked = false; pumpOn = true; break;
            }
            c.Status = CommandStatus.Done;
            c.DeliveredAt = now;
        }

        // Cooldown debt paid → stop the parked pump.
        if (_parked && now >= _cooldownDeadline) { _parked = false; pumpOn = false; }

        bool lockout = _heaterLastOff.HasValue && (now - _heaterLastOff.Value).TotalSeconds < LockoutSeconds;
        bool wantHeat = systemOn && pumpOn && !_parked && temp < target;

        if (wantHeat && !lockout) heaterOn = true;
        else if (heaterOn) { heaterOn = false; _heaterLastOff = now; }

        // Temperature physics: +1 °F / 5s heating toward target, -1 °F / 10s cooling to baseline.
        bool heating = heaterOn && temp < target;
        bool cooling = !heaterOn && temp > Baseline;
        if (heating && (now - _lastTempStep).TotalSeconds >= HeatStepSeconds) { temp += 1; _lastTempStep = now; }
        else if (cooling && (now - _lastTempStep).TotalSeconds >= CoolStepSeconds) { temp -= 1; _lastTempStep = now; }
        else if (!heating && !cooling) { _lastTempStep = now; }   // settled — reset the step clock

        // Derived display strings (match the firmware's text_sensors).
        string pumpStatus = _parked ? "Cooling Down" : (pumpOn ? "ON" : "OFF");
        string heaterStatus;
        if (heaterOn) heaterStatus = "ON";
        else if (wantHeat && lockout)
        {
            int rem = LockoutSeconds - (int)(now - _heaterLastOff!.Value).TotalSeconds;
            heaterStatus = $"Temp Lockout {Mmss(rem)}";
        }
        else heaterStatus = "OFF";
        string cooldownRemaining = now < _cooldownDeadline ? Mmss((int)(_cooldownDeadline - now).TotalSeconds) : "--:--";

        // Persist snapshot; keep the device "online".
        s.WaterTemp = Math.Round(temp, 1);
        s.TargetTemp = target;
        s.SystemOn = systemOn;
        s.PumpOn = pumpOn;
        s.HeaterOn = heaterOn;
        s.HeaterStatus = heaterStatus;
        s.PumpStatus = pumpStatus;
        s.CooldownRemaining = cooldownRemaining;
        s.ReportedAt = now;
        device.LastSeenAt = now;

        // Throttled history so the demo's history chart fills in.
        if ((now - _lastHistory).TotalSeconds >= _opt.HistoryIntervalSeconds)
        {
            db.History.Add(new HistoryEntry
            {
                DeviceId = device.Id, RecordedAt = now, WaterTemp = s.WaterTemp,
                TargetTemp = target, PumpOn = pumpOn, SystemOn = systemOn, HeaterOn = heaterOn,
                PumpStatus = pumpStatus,
            });
            _lastHistory = now;
        }

        await db.SaveChangesAsync(ct);
    }

    private static string Mmss(int totalSeconds)
    {
        if (totalSeconds < 0) totalSeconds = 0;
        return $"{totalSeconds / 60:D2}:{totalSeconds % 60:D2}";
    }
}
