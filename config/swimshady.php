<?php

return [

    /*
    | Device is "online" when its last heartbeat (or demo tick) is at least this fresh.
    | The ESP checks in every 30s, so 90s tolerates two missed beats.
    */
    'offline_after_seconds' => (int) env('OFFLINE_AFTER_SECONDS', 90),

    'history_interval_seconds' => (int) env('HISTORY_INTERVAL_SECONDS', 60),
    'history_retention_days' => (int) env('HISTORY_RETENTION_DAYS', 90),

    // Fail2Ban: N failures inside the window auto-bans the IP.
    'ban_threshold' => (int) env('BAN_THRESHOLD', 5),
    'ban_window_seconds' => (int) env('BAN_WINDOW_SECONDS', 30),
    'ban_duration_minutes' => (int) env('BAN_DURATION_MINUTES', 60),
    'log_retention_days' => (int) env('LOG_RETENTION_DAYS', 30),

    // Comma-separated IPs that are always allowed and never auto-banned.
    // Whitelist the pool's public IP so a bad token can't lock the heater out.
    'whitelist_ips' => (string) env('WHITELIST_IPS', ''),

    // Proxies whose X-Forwarded-* headers are trusted. RunCloud's nginx is local.
    // Use "*" only if a reverse proxy in front of nginx is not on this list.
    'trusted_proxies' => (string) env('TRUSTED_PROXIES', '127.0.0.1'),

    'display_timezone' => (string) env('DISPLAY_TIMEZONE', 'America/Indiana/Indianapolis'),

    'ui_poll_ms' => (int) env('UI_POLL_MS', 5000),

    'firmware_max_bytes' => (int) env('FIRMWARE_MAX_BYTES', 16_000_000),

    'demo_firmware_version' => '2026.06.01',
];
