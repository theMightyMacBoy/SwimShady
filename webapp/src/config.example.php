<?php
// Copy to config.php and set your API base URL. No secrets live here — user auth
// is a per-session JWT the API issues at login.
declare(strict_types=1);

return [
    'api_base' => 'https://<your-app>.azurewebsites.net',

    'app' => [
        'name'          => 'Swim Shady',
        'session_name'  => 'POOLSESS',
        'cookie_secure' => false,   // true when served over HTTPS
        'poll_ms'       => 5000,
    ],
];
