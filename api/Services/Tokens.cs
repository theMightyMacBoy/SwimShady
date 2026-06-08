using System.Security.Cryptography;
using System.Text;

namespace SwimShady.Api.Services;

public static class Tokens
{
    /// <summary>Generate a 48-char hex device token (same scheme as the old PHP app).</summary>
    public static string NewDeviceToken() => Convert.ToHexString(RandomNumberGenerator.GetBytes(24)).ToLowerInvariant();

    /// <summary>SHA-256 hex of a token, for storage/lookup.</summary>
    public static string Hash(string token)
        => Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(token))).ToLowerInvariant();
}

public static class Commands
{
    private static readonly HashSet<string> Simple =
        new(StringComparer.Ordinal) { "system_on", "system_off", "pump_on", "pump_off", "cancel_cooldown" };

    /// <summary>Validate + normalize a command. Throws ArgumentException on bad input.</summary>
    public static (string Action, string? Value) Normalize(string action, string? value)
    {
        if (action == "set_target")
        {
            if (!int.TryParse(value, out var v) || v < 70 || v > 104)
                throw new ArgumentException("target temp must be 70–104");
            return ("set_target", v.ToString());
        }
        if (!Simple.Contains(action))
            throw new ArgumentException("unknown action");
        return (action, null);
    }
}
