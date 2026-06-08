namespace SwimShady.Api.Services;

public class AppSettings
{
    public int OfflineAfterSeconds { get; set; } = 20;
    public int HistoryIntervalSeconds { get; set; } = 60;
    public int HistoryRetentionDays { get; set; } = 90;

    // Fail2Ban: N failures within the window auto-bans the IP for the duration.
    public int BanThreshold { get; set; } = 5;
    public int BanWindowSeconds { get; set; } = 30;
    public int BanDurationMinutes { get; set; } = 60;

    // Login + API request logs (and expired auto-bans) are pruned after this many days.
    public int LogRetentionDays { get; set; } = 30;

    // Comma-separated IPs to permanently whitelist (always allowed, never auto-banned).
    // Set via config/Azure app setting (App__WhitelistIps) — keep real IPs out of source.
    public string? WhitelistIps { get; set; }
}
