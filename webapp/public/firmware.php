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
        if ($do === 'upload') {
            $version = trim($_POST['version'] ?? '');
            $channel = $_POST['channel'] ?? 'stable';
            $notes   = trim($_POST['notes'] ?? '');
            if ($version === '') {
                $error = 'Version is required.';
            } elseif (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $error = 'Select a firmware .bin file.';
            } else {
                $res = api_upload('/api/firmware',
                    ['version' => $version, 'channel' => $channel, 'notes' => $notes],
                    'file', $_FILES['file']['tmp_name'], $_FILES['file']['name'], $user['jwt']);
                if ($res['status'] === 200) {
                    $_SESSION['flash_msg'] = 'Firmware ' . $version . ' uploaded.';
                    redirect('firmware.php');
                }
                $error = $res['data']['error'] ?? 'Upload failed.';
            }
        } elseif ($do === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $res = api_delete('/api/firmware/' . $id, $user['jwt']);
            if ($res['status'] === 204 || $res['status'] === 200) {
                redirect('firmware.php');
            }
            $error = $res['data']['error'] ?? 'Could not delete.';
        }
    }
}

$flash = $_SESSION['flash_msg'] ?? null;
unset($_SESSION['flash_msg']);

$list = is_array(($r = api_get('/api/firmware', $user['jwt']))['data'] ?? null) ? $r['data'] : [];
$latest = $list[0] ?? null;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($appName) ?> — Firmware</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <header class="topbar">
    <h1>Firmware</h1>
    <div class="topbar-right">
      <a class="logout" href="devices.php">Devices</a>
      <a class="logout" href="admin-ips.php">IP Security</a>
      <a class="logout" href="index.php">← Dashboard</a>
    </div>
  </header>

  <main class="history-main">
    <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
    <?php if ($flash): ?><p class="success"><?= e($flash) ?></p><?php endif; ?>

    <div class="card">
      <div class="label">Upload firmware (.ota.bin)</div>
      <p class="muted">Build with <code>esphome compile ESP32/pool-temp.yaml</code> →
        upload <code>firmware.ota.bin</code>. The <strong>version</strong> must match the
        <code>firmware_version</code> substitution in that build. The latest upload becomes
        the target; devices not on it self-update when idle.</p>
      <form method="post" enctype="multipart/form-data" class="add-device" style="flex-direction:column;align-items:stretch;max-width:520px">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="do" value="upload">
        <label>Version <input type="text" name="version" placeholder="2026.06.08.1" required></label>
        <label>Channel
          <select name="channel" class="device-select">
            <option value="stable">stable</option>
            <option value="dev">dev</option>
          </select>
        </label>
        <label>Notes (optional) <input type="text" name="notes" placeholder="what changed"></label>
        <label>File <input type="file" name="file" accept=".bin" required></label>
        <button type="submit">Upload</button>
      </form>
    </div>

    <div class="card">
      <div class="label">Uploaded builds <span class="muted">(newest per channel = target)</span></div>
      <?php if (!$list): ?>
        <p class="muted">No firmware uploaded yet.</p>
      <?php else: $badged = []; ?>
        <table class="devices">
          <thead><tr><th>Version</th><th>Channel</th><th>Size</th><th>MD5</th><th>Uploaded</th><th>Notes</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($list as $f): $isTarget = empty($badged[$f['channel']]); $badged[$f['channel']] = true; ?>
            <tr>
              <td><?= e($f['version']) ?><?= $isTarget ? ' <span class="badge badge-on">target</span>' : '' ?></td>
              <td class="muted"><?= e($f['channel']) ?></td>
              <td class="muted"><?= number_format((int) $f['size'] / 1024, 0) ?> KB</td>
              <td class="muted"><code><?= e(substr($f['md5'], 0, 10)) ?>…</code></td>
              <td class="muted"><?= e($f['createdAt'] ?? '') ?></td>
              <td class="muted"><?= e($f['notes'] ?? '') ?></td>
              <td class="row-actions">
                <form method="post" onsubmit="return confirm('Delete firmware <?= e($f['version']) ?>?');">
                  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                  <input type="hidden" name="do" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                  <button class="btn-danger" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
