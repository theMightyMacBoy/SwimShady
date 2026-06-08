<?php
// Common bootstrap for every entry point.
declare(strict_types=1);

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit("Missing src/config.php — copy src/config.example.php and set api_base.\n");
}

/** @var array $config */
$config = require $configFile;

require __DIR__ . '/helpers.php';
require __DIR__ . '/api_client.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/csrf.php';
