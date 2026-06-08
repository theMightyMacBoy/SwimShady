<?php
require __DIR__ . '/../src/bootstrap.php';
$user = require_login();
$csrf = csrf_token();
$appName = config('app')['name'] ?? 'Swim Shady';
$pollMs = (int) (config('app')['poll_ms'] ?? 5000);

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
$canControl = can_control($user);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($appName) ?></title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body
  data-csrf="<?= e($csrf) ?>"
  data-poll-ms="<?= $pollMs ?>"
  data-device-id="<?= $current ? (int) $current['id'] : '' ?>"
  data-can-control="<?= $canControl ? '1' : '0' ?>">

  <header class="topbar">
    <h1><?= e($appName) ?></h1>
    <div class="topbar-right">
      <?php if (count($devices) > 1): ?>
        <select id="deviceSelect" class="device-select">
          <?php foreach ($devices as $d): ?>
            <option value="<?= (int) $d['id'] ?>" <?= ($current && $d['id'] == $current['id']) ? 'selected' : '' ?>>
              <?= e($d['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
      <?php if ($current): ?>
        <button id="refreshBtn" class="btn-secondary" type="button">↻ Refresh</button>
      <?php endif; ?>
      <span id="conn" class="conn conn-unknown" title="Connection">●</span>
      <span class="user"><?= e($user['username']) ?> · <?= e($user['role']) ?></span>
      <?php if (is_admin($user)): ?>
        <a class="logout" href="devices.php">Devices</a>
        <a class="logout" href="users.php">Users</a>
        <a class="logout" href="firmware.php">Firmware</a>
        <a class="logout" href="admin-ips.php">IP Security</a>
      <?php endif; ?>
      <a class="logout" href="account.php">Account</a>
      <a class="logout" href="logout.php">Sign out</a>
    </div>
  </header>

  <?php if (!$current): ?>
    <main class="grid">
      <section class="card">
        <div class="label">No device available</div>
        <p class="muted">
          <?php if (is_admin($user)): ?>
            Register your ESP32 on the <a href="devices.php">Devices</a> page, then flash its token.
          <?php else: ?>
            No devices are assigned to your account. Ask an admin to assign one.
          <?php endif; ?>
        </p>
      </section>
    </main>
  <?php else: ?>
  <?php if (!$canControl): ?>
    <p class="readonly-note">Read-only access — you can view this device but not control it.</p>
  <?php endif; ?>
  <main class="grid">
    <section class="card temp-card">
      <div class="label">Water Temperature</div>
      <div id="waterTemp" class="big-temp">--<span class="unit">°F</span></div>
      <div id="updated" class="muted">Connecting…</div>
    </section>

    <section class="card">
      <div class="label">Target Temperature</div>
      <div class="target-row">
        <input type="range" id="targetSlider" min="70" max="104" step="1" value="82" <?= $canControl ? '' : 'disabled' ?>>
        <output id="targetValue" class="target-value">82°F</output>
      </div>
      <div class="muted">Heater holds the pool at this temperature (70–104 °F).</div>
    </section>

    <section class="card">
      <div class="label">Pool Heat System</div>
      <button id="systemToggle" class="toggle" data-on="false" <?= $canControl ? '' : 'disabled' ?>>OFF</button>
      <div class="muted">Master enable for heating.</div>
    </section>

    <section class="card">
      <div class="label">Pool Pump</div>
      <button id="pumpToggle" class="toggle" data-on="false" <?= $canControl ? '' : 'disabled' ?>>OFF</button>
      <div class="muted">Turning off starts the 5-minute cool-down.</div>
    </section>

    <section class="card status-card">
      <div class="label">Pump Status</div>
      <div id="pumpStatus" class="status">—</div>
      <div id="cooldownWrap" class="cooldown hidden">
        Cool-down remaining: <span id="cooldown" class="mono">--:--</span>
        <span class="tip" tabindex="0" role="img" aria-label="Why is the pump still running?"
          data-tip="After the heater shuts off, the pump keeps circulating for 5 minutes so the heat exchanger fully cools before the water stops moving. This protects the heater from residual heat.">i</span>
        <?php if ($canControl): ?>
          <button id="cancelCooldown" class="btn-secondary">Cancel cool-down</button>
        <?php endif; ?>
      </div>
    </section>

    <section class="card">
      <div class="label">Heater</div>
      <div id="heaterIndicator" class="indicator">
        <span class="dot"></span><span id="heaterText">OFF</span>
        <span id="heaterTip" class="tip hidden" tabindex="0" role="img" aria-label="Why isn't the heater firing?"
          data-tip="The heater ran within the last few minutes. To avoid rapid on/off cycling, it waits a short time before firing again.">i</span>
      </div>
      <div class="muted">Lit while the burner is firing (read-only).</div>
    </section>
  </main>

  <p class="footer">
    <span class="muted"><?= e($current['name']) ?> · firmware <?= e($current['firmwareVersion'] ?? '—') ?> · branch <?= e($current['channel'] ?? 'stable') ?></span><br>
    <a href="history.php?device=<?= (int) $current['id'] ?>">View temperature history →</a>
  </p>
  <?php endif; ?>

  <div id="toast" class="toast hidden"></div>

  <script src="assets/app.js?v=8"></script>
</body>
</html>
