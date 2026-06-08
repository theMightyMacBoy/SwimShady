<?php
// Proxy: queue a command via the Azure API (CSRF-protected, session JWT).
require __DIR__ . '/../../src/bootstrap.php';
$user = require_login_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'method not allowed'], 405);
}

$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? null);
if (!csrf_check($token)) {
    json_out(['error' => 'bad csrf token'], 403);
}

$deviceId = (int) ($_POST['device'] ?? 0);
$action   = (string) ($_POST['action'] ?? '');
$value    = $_POST['value'] ?? null;
if ($deviceId <= 0 || $action === '') {
    json_out(['error' => 'device and action required'], 400);
}

$body = ['action' => $action, 'value' => $value !== null ? (string) $value : null];
$res = api_post('/api/devices/' . $deviceId . '/commands', $body, $user['jwt']);
json_out($res['data'] ?? ['error' => 'api error'], $res['status'] ?: 502);
