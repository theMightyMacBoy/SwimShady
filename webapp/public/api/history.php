<?php
// Proxy: device history from the Azure API (session JWT).
require __DIR__ . '/../../src/bootstrap.php';
$user = require_login_api();

$deviceId = (int) ($_GET['device'] ?? 0);
$hours    = (int) ($_GET['hours'] ?? 24);
if ($deviceId <= 0) {
    json_out(['hours' => 0, 'points' => []]);
}

$res = api_get('/api/devices/' . $deviceId . '/history?hours=' . $hours, $user['jwt']);
json_out($res['data'] ?? ['hours' => 0, 'points' => []], $res['status'] ?: 502);
