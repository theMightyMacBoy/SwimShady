using System.Text.Json.Serialization;

namespace SwimShady.Api.Dtos;

// ---- Device-facing (heartbeat) ----
// snake_case names match what the firmware sends (see webapp/DEVICE_API.md).

public record HeartbeatRequest(
    [property: JsonPropertyName("water_temp")] double? WaterTemp,
    [property: JsonPropertyName("target_temp")] int? TargetTemp,
    [property: JsonPropertyName("pump_on")] bool? PumpOn,
    [property: JsonPropertyName("system_on")] bool? SystemOn,
    [property: JsonPropertyName("heater_on")] bool? HeaterOn,
    [property: JsonPropertyName("heater_status")] string? HeaterStatus,
    [property: JsonPropertyName("pump_status")] string? PumpStatus,
    [property: JsonPropertyName("cooldown_remaining")] string? CooldownRemaining,
    [property: JsonPropertyName("firmware_version")] string? FirmwareVersion
);

public record CommandDto(long Id, string Action, string? Value);

/// <summary>Last reported desired state, returned to the device on boot so it can resume.</summary>
public record DeviceStateResponse(bool HasState, int? TargetTemp, bool? SystemOn, bool? PumpOn);

/// <summary>Offered to the device when a newer firmware build is available.</summary>
public record FirmwareUpdateDto(string Version, string Url, string Md5Url);

public record HeartbeatResponse(bool Ok, IReadOnlyList<CommandDto> Commands, FirmwareUpdateDto? Update, string ServerTime);

// ---- Auth ----

public record LoginRequest(string Username, string Password);
public record LoginResponse(string Token, string Username, string Role, DateTime ExpiresAt);
public record ChangePasswordRequest(string Current, string New);

// ---- Devices / state / history (UI-facing) ----

public record DeviceDto(int Id, string Uid, string Name, bool Online, DateTime? LastSeenAt, string? FirmwareVersion, string Channel);

public record SetChannelRequest(string Channel);

public record FirmwareDto(int Id, string Version, string Channel, string Md5, int Size, DateTime CreatedAt, string? Notes);

public record CreateDeviceRequest(string Uid, string Name);
public record CreateDeviceResponse(int Id, string Uid, string Name, string Token);

public record StateDto(
    bool Online,
    int DeviceId,
    string DeviceName,
    double? WaterTemp,
    string? WaterTempDisplay,
    int? TargetTemp,
    bool? SystemOn,
    bool? PumpOn,
    string? PumpStatus,
    string? CooldownRemaining,
    bool? HeaterOn,
    string? HeaterStatus,
    DateTime? ReportedAt,
    int Pending,
    string ServerTime
);

public record CommandRequest(string Action, string? Value);

public record HistoryPointDto(DateTime RecordedAt, double? WaterTemp, int? TargetTemp, bool? HeaterOn);
public record HistoryResponse(int Hours, int DeviceId, IReadOnlyList<HistoryPointDto> Points);

// ---- Users (admin) ----

public record UserDto(int Id, string Username, string Role, IReadOnlyList<int> DeviceIds, DateTime? LastLoginAt);
public record CreateUserRequest(string Username, string Password, string Role, int[]? DeviceIds);
public record UpdateUserRequest(string Role, string? Password, int[]? DeviceIds);

// ---- IP security (admin) ----

public record IpRuleDto(int Id, string Ip, string Kind, string Source, string? Reason, DateTime? ExpiresAt, DateTime CreatedAt);
public record CreateIpRuleRequest(string Ip, string Kind, string? Note);   // kind: "allow" | "deny"
public record LoginAttemptDto(long Id, string Ip, string? Username, bool Success, DateTime CreatedAt);
public record ApiRequestLogDto(long Id, string Ip, string Method, string Path, int StatusCode, int? UserId, DateTime CreatedAt);
