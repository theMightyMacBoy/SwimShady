namespace SwimShady.Api.Models;

public static class Roles
{
    public const string Admin = "admin";
    public const string Standard = "standard";
    public const string ReadOnly = "readonly";

    public static readonly string[] All = { Admin, Standard, ReadOnly };

    /// <summary>Admins and standard users may issue commands; readonly may not.</summary>
    public static bool CanControl(string role) => role is Admin or Standard;
}

public static class Channels
{
    public const string Stable = "stable";
    public const string Dev = "dev";

    public static readonly string[] All = { Stable, Dev };
}

public class User
{
    public int Id { get; set; }
    public string Username { get; set; } = "";
    public string PasswordHash { get; set; } = "";
    public string Role { get; set; } = Roles.Standard;
    public DateTime CreatedAt { get; set; } = DateTime.UtcNow;
    public DateTime? LastLoginAt { get; set; }

    public List<UserDevice> UserDevices { get; set; } = new();
}

public class Device
{
    public int Id { get; set; }
    public string Uid { get; set; } = "";        // slug or GUID, matches firmware device_uid
    public string Name { get; set; } = "";
    public string TokenHash { get; set; } = "";   // sha256(token) hex
    public DateTime CreatedAt { get; set; } = DateTime.UtcNow;
    public DateTime? LastSeenAt { get; set; }
    public string? FirmwareVersion { get; set; }   // last version the device reported
    public string Channel { get; set; } = Channels.Stable; // OTA channel: stable | dev
    public bool IsDemo { get; set; }                // true for the public simulated demo device

    public DeviceState? State { get; set; }
    public List<UserDevice> UserDevices { get; set; } = new();
}

/// <summary>An uploaded firmware build (the OTA .bin stored in the DB) for self-update.</summary>
public class Firmware
{
    public int Id { get; set; }
    public string Version { get; set; } = "";
    public string Channel { get; set; } = Channels.Stable; // stable | dev
    public string Md5 { get; set; } = "";       // hex md5 of Data, for OTA verification
    public int Size { get; set; }
    public byte[] Data { get; set; } = Array.Empty<byte>();
    public DateTime CreatedAt { get; set; } = DateTime.UtcNow;
    public string? Notes { get; set; }
}

/// <summary>Which non-admin users may see/control which devices. Admins ignore this.</summary>
public class UserDevice
{
    public int UserId { get; set; }
    public User User { get; set; } = null!;
    public int DeviceId { get; set; }
    public Device Device { get; set; } = null!;
}

/// <summary>Latest reported snapshot per device (one row per device, upserted).</summary>
public class DeviceState
{
    public int DeviceId { get; set; }
    public Device Device { get; set; } = null!;
    public double? WaterTemp { get; set; }
    public int? TargetTemp { get; set; }
    public bool? PumpOn { get; set; }
    public bool? SystemOn { get; set; }
    public bool? HeaterOn { get; set; }
    public string? HeaterStatus { get; set; }
    public string? PumpStatus { get; set; }
    public string? CooldownRemaining { get; set; }
    public DateTime ReportedAt { get; set; } = DateTime.UtcNow;
}

public class HistoryEntry
{
    public long Id { get; set; }
    public int DeviceId { get; set; }
    public DateTime RecordedAt { get; set; } = DateTime.UtcNow;
    public double? WaterTemp { get; set; }
    public int? TargetTemp { get; set; }
    public bool? PumpOn { get; set; }
    public bool? SystemOn { get; set; }
    public bool? HeaterOn { get; set; }
    public string? PumpStatus { get; set; }
}

public enum CommandStatus { Pending = 0, Done = 1 }

public class CommandQueueItem
{
    public long Id { get; set; }
    public int DeviceId { get; set; }
    public string Action { get; set; } = "";
    public string? Value { get; set; }
    public CommandStatus Status { get; set; } = CommandStatus.Pending;
    public int? CreatedBy { get; set; }
    public DateTime CreatedAt { get; set; } = DateTime.UtcNow;
    public DateTime? DeliveredAt { get; set; }
}

// ---- IP security / abuse protection ----

/// <summary>Source IP of every login attempt (30-day retention).</summary>
public class LoginAttempt
{
    public long Id { get; set; }
    public string Ip { get; set; } = "";
    public string? Username { get; set; }
    public bool Success { get; set; }
    public DateTime CreatedAt { get; set; } = DateTime.UtcNow;
}

/// <summary>Source IP + outcome of every API request (30-day retention).</summary>
public class ApiRequest
{
    public long Id { get; set; }
    public string Ip { get; set; } = "";
    public string Method { get; set; } = "";
    public string Path { get; set; } = "";
    public int StatusCode { get; set; }
    public int? UserId { get; set; }
    public DateTime CreatedAt { get; set; } = DateTime.UtcNow;
}

public enum IpRuleKind { Allow = 0, Deny = 1 }      // Allow = whitelist (always wins), Deny = block/ban
public enum IpRuleSource { Manual = 0, Auto = 1 }   // Manual = admin (kept forever), Auto = Fail2Ban (transient)

/// <summary>Whitelist / block / auto-ban for an IP. An active Allow beats any Deny.</summary>
public class IpRule
{
    public int Id { get; set; }
    public string Ip { get; set; } = "";
    public IpRuleKind Kind { get; set; }
    public IpRuleSource Source { get; set; }
    public string? Reason { get; set; }
    public DateTime? ExpiresAt { get; set; }            // null = permanent
    public DateTime CreatedAt { get; set; } = DateTime.UtcNow;
    public int? CreatedBy { get; set; }
}
