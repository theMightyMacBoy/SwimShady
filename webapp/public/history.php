<?php
require __DIR__ . '/../src/bootstrap.php';
$user = require_login();
$appName = config('app')['name'] ?? 'Swim Shady';

$devRes  = api_get('/api/devices', $user['jwt']);
$devices = is_array($devRes['data'] ?? null) ? $devRes['data'] : [];

$selected = (int) ($_GET['device'] ?? 0);
$current = null;
foreach ($devices as $d) {
    if ($selected && (int) $d['id'] === $selected) {
        $current = $d;
        break;
    }
}
if (!$current) {
    $current = $devices[0] ?? null;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($appName) ?> — History</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body data-device-id="<?= $current ? (int) $current['id'] : '' ?>">
  <header class="topbar">
    <h1>Temperature History<?= $current ? ' — ' . e($current['name']) : '' ?></h1>
    <div class="topbar-right">
      <a class="logout" href="index.php<?= $current ? '?device=' . (int) $current['id'] : '' ?>">← Back</a>
    </div>
  </header>

  <main class="history-main">
    <div class="range-tabs">
      <button class="range-btn active" data-hours="24">24h</button>
      <button class="range-btn" data-hours="72">3d</button>
      <button class="range-btn" data-hours="168">7d</button>
      <button class="range-btn" data-hours="720">30d</button>
    </div>
    <div class="card">
      <canvas id="chart" width="900" height="360"></canvas>
      <div id="chartEmpty" class="muted hidden">No history recorded yet.</div>
    </div>
    <p class="muted legend">
      <span class="key key-water"></span> Water temp
      <span class="key key-target"></span> Target
      <span class="key key-heat"></span> Heater on
    </p>
  </main>

  <script src="assets/history.js?v=2"></script>
</body>
</html>
