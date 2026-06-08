<?php
// Proxy: latest device state from the Azure API (using the session JWT).
require __DIR__ . '/../../src/bootstrap.php';
$user = require_login_api();

$deviceId = (int) ($_GET['device'] ?? 0);
if ($deviceId <= 0) {
    json_out(['error' => 'device required'], 400);
}

$res = api_get('/api/devices/' . $deviceId . '/state', $user['jwt']);
json_out($res['data'] ?? ['error' => 'api error'], $res['status'] ?: 502);
