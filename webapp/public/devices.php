<?php
require __DIR__ . '/../src/bootstrap.php';
$user = require_admin();
$csrf = csrf_token();
$appName = config('app')['name'] ?? 'Swim Shady';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Session expired — please try again.';
    } else {
        $do = $_POST['do'] ?? '';
        if ($do === 'add') {
            $uid  = strtolower(trim($_POST['uid'] ?? ''));
            $name = trim($_POST['name'] ?? '');
            $res = api_post('/api/devices', ['uid' => $uid, 'name' => $name], $user['jwt']);
            if ($res['status'] === 200 && !empty($res['data']['token'])) {
                $_SESSION['flash_token']     = $res['data']['token'];
                $_SESSION['flash_token_uid'] = $res['data']['uid'] ?? $uid;
                redirect('devices.php');
            }
            $error = $res['data']['error'] ?? 'Could not create device.';
        } elseif ($do === 'regen') {
            $id = (int) ($_POST['id'] ?? 0);
            $res = api_post('/api/devices/' . $id . '/regenerate-token', null, $user['jwt']);
            if ($res['status'] === 200 && !empty($res['data']['token'])) {
                $_SESSION['flash_token']     = $res['data']['token'];
                $_SESSION['flash_token_uid'] = $_POST['uid'] ?? ('device ' . $id);
                redirect('devices.php');
            }
            $error = $res['data']['error'] ?? 'Could not regenerate token.';
        } elseif ($do === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $res = api_delete('/api/devices/' . $id, $user['jwt']);
            if ($res['status'] === 204 || $res['status'] === 200) {
                redirect('devices.php');
            }
            $error = $res['data']['error'] ?? 'Could not delete device.';
        } elseif ($do === 'channel') {
            $id = (int) ($_POST['id'] ?? 0);
            $res = api_post('/api/devices/' . $id . '/channel', ['channel' => $_POST['channel'] ?? 'stable'], $user['jwt']);
            if ($res['status'] === 200) {
                redirect('devices.php');
            }
            $error = $res['data']['error'] ?? 'Could not set channel.';
        }
    }
}

$newToken    = $_SESSION['flash_token'] ?? null;
$newTokenUid = $_SESSION['flash_token_uid'] ?? null;
unset($_SESSION['flash_token'], $_SESSION['flash_token_uid']);

$listRes = api_get('/api/devices', $user['jwt']);
$devices = is_array($listRes['data'] ?? null) ? $listRes['data'] : [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($appName) ?> — Devices</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <header class="topbar">
    <h1>Devices</h1>
    <div class="topbar-right">
      <a class="logout" href="users.php">Users</a>
      <a class="logout" href="firmware.php">Firmware</a>
      <a class="logout" href="admin-ips.php">IP Security</a>
      <a class="logout" href="index.php">← Dashboard</a>
    </div>
  </header>

  <main class="history-main">
    <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>

    <?php if ($newToken): ?>
      <div class="card token-banner">
        <div class="label">Device token for <?= e($newTokenUid) ?></div>
        <p class="muted">Copy this now — shown only once. Put it in the firmware (<code>secrets.yaml</code> → <code>swimshady_token</code>).</p>
        <code class="token"><?= e($newToken) ?></code>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="label">Registered devices</div>
      <?php if (!$devices): ?>
        <p class="muted">No devices yet. Add one below, then flash its token into the firmware.</p>
      <?php else: ?>
        <table class="devices">
          <thead><tr><th>Name</th><th>UID</th><th>Status</th><th>Firmware</th><th>Channel</th><th>Last seen</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($devices as $d): ?>
            <tr>
              <td><?= e($d['name']) ?></td>
              <td><code><?= e($d['uid']) ?></code></td>
              <td>
                <?php if (!empty($d['online'])): ?>
                  <span class="badge badge-on">online</span>
                <?php else: ?>
                  <span class="badge badge-off">offline</span>
                <?php endif; ?>
              </td>
              <td class="muted"><?= e($d['firmwareVersion'] ?? '—') ?></td>
              <td>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                  <input type="hidden" name="do" value="channel">
                  <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                  <select name="channel" class="device-select" onchange="this.form.submit()">
                    <option value="stable" <?= ($d['channel'] ?? 'stable') === 'stable' ? 'selected' : '' ?>>stable</option>
                    <option value="dev" <?= ($d['channel'] ?? 'stable') === 'dev' ? 'selected' : '' ?>>dev</option>
                  </select>
                </form>
              </td>
              <td class="muted"><?= e($d['lastSeenAt'] ?? 'never') ?></td>
              <td class="row-actions">
                <form method="post" onsubmit="return confirm('Regenerate token? The old one stops working.');">
                  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                  <input type="hidden" name="do" value="regen">
                  <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                  <input type="hidden" name="uid" value="<?= e($d['uid']) ?>">
                  <button class="btn-secondary" type="submit">New token</button>
                </form>
                <form method="post" onsubmit="return confirm('Delete this device and all its history?');">
                  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                  <input type="hidden" name="do" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                  <button class="btn-danger" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="label">Add a device</div>
      <form method="post" class="add-device">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="do" value="add">
        <label>Name <input type="text" name="name" placeholder="Pool ESP32" required></label>
        <label>UID <input type="text" name="uid" placeholder="pool-esp32" required></label>
        <button type="submit">Add device</button>
      </form>
    </div>
  </main>
</body>
</html>
