<?php
require __DIR__ . '/../src/bootstrap.php';
$me = require_admin();
$csrf = csrf_token();
$appName = config('app')['name'] ?? 'Swim Shady';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Session expired — please try again.';
    } else {
        $do = $_POST['do'] ?? '';
        if ($do === 'add') {
            $body = [
                'ip'   => trim($_POST['ip'] ?? ''),
                'kind' => $_POST['kind'] ?? '',          // "allow" (whitelist) | "deny" (block)
                'note' => trim($_POST['note'] ?? ''),
            ];
            $res = api_post('/api/admin/ips/rules', $body, $me['jwt']);
            if ($res['status'] === 200) {
                $_SESSION['flash_msg'] = ($body['kind'] === 'allow' ? 'Whitelist' : 'Block') . ' rule added.';
                redirect('admin-ips.php');
            }
            $error = $res['data']['error'] ?? 'Could not add rule.';
        } elseif ($do === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $res = api_delete('/api/admin/ips/rules/' . $id, $me['jwt']);
            if ($res['status'] === 204 || $res['status'] === 200) {
                $_SESSION['flash_msg'] = 'Rule removed.';
                redirect('admin-ips.php');
            }
            $error = $res['data']['error'] ?? 'Could not remove rule.';
        }
    }
}

$flash = $_SESSION['flash_msg'] ?? null;
unset($_SESSION['flash_msg']);

$hours    = max(1, min(720, (int) ($_GET['hours'] ?? 24)));
$rules    = is_array(($r = api_get('/api/admin/ips/rules', $me['jwt']))['data'] ?? null) ? $r['data'] : [];
$logins   = is_array(($l = api_get('/api/admin/ips/logins?hours=' . $hours, $me['jwt']))['data'] ?? null) ? $l['data'] : [];
$requests = is_array(($q = api_get('/api/admin/ips/requests?hours=' . $hours, $me['jwt']))['data'] ?? null) ? $q['data'] : [];

/** Format an ISO timestamp for display (or a dash). */
function ts(?string $iso): string
{
    if (!$iso) return '—';
    $t = strtotime($iso);
    return $t ? date('Y-m-d H:i:s', $t) : e($iso);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($appName) ?> — IP Security</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <header class="topbar">
    <h1>IP Security</h1>
    <div class="topbar-right">
      <a class="logout" href="devices.php">Devices</a>
      <a class="logout" href="users.php">Users</a>
      <a class="logout" href="firmware.php">Firmware</a>
      <a class="logout" href="index.php">← Dashboard</a>
    </div>
  </header>

  <main class="history-main">
    <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
    <?php if ($flash): ?><p class="success"><?= e($flash) ?></p><?php endif; ?>

    <div class="card">
      <div class="label">Add rule</div>
      <p class="muted">A <strong>whitelist</strong> always wins and is never auto-banned. A <strong>block</strong> is a permanent manual ban. Both are kept forever. Auto-bans (5 failures in 30 s) expire on their own.</p>
      <form method="post" class="user-row">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="do" value="add">
        <div class="user-head">
          <input type="text" name="ip" class="inline-input" placeholder="IP address (e.g. 203.0.113.7)" required>
          <select name="kind" class="device-select">
            <option value="allow">whitelist (allow)</option>
            <option value="deny">block (deny)</option>
          </select>
          <input type="text" name="note" class="inline-input" placeholder="note (optional)">
        </div>
        <div class="row-actions"><button type="submit">Add rule</button></div>
      </form>
    </div>

    <div class="card">
      <div class="label">Rules &amp; active bans</div>
      <?php if (!$rules): ?>
        <p class="muted">No rules or bans.</p>
      <?php else: ?>
        <table class="data-table">
          <thead><tr><th>IP</th><th>Kind</th><th>Source</th><th>Reason</th><th>Expires</th><th>Created</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($rules as $r): ?>
            <tr>
              <td class="mono"><?= e($r['ip'] ?? '') ?></td>
              <td><?= ($r['kind'] ?? '') === 'allow' ? '✅ allow' : '⛔ deny' ?></td>
              <td><?= e($r['source'] ?? '') ?></td>
              <td><?= e($r['reason'] ?? '') ?></td>
              <td class="mono"><?= ts($r['expiresAt'] ?? null) ?></td>
              <td class="mono"><?= ts($r['createdAt'] ?? null) ?></td>
              <td>
                <form method="post" onsubmit="return confirm('Remove this rule?');">
                  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                  <input type="hidden" name="do" value="delete">
                  <input type="hidden" name="id" value="<?= (int) ($r['id'] ?? 0) ?>">
                  <button type="submit" class="btn-danger">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="label">Recent logins (last <?= (int) $hours ?> h)</div>
      <?php if (!$logins): ?>
        <p class="muted">No login attempts in this window.</p>
      <?php else: ?>
        <table class="data-table">
          <thead><tr><th>When</th><th>IP</th><th>Username</th><th>Result</th></tr></thead>
          <tbody>
          <?php foreach ($logins as $l): ?>
            <tr>
              <td class="mono"><?= ts($l['createdAt'] ?? null) ?></td>
              <td class="mono"><?= e($l['ip'] ?? '') ?></td>
              <td><?= e($l['username'] ?? '') ?></td>
              <td><?= !empty($l['success']) ? '✅ ok' : '❌ failed' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="label">Recent API requests (last <?= (int) $hours ?> h)</div>
      <?php if (!$requests): ?>
        <p class="muted">No API requests in this window.</p>
      <?php else: ?>
        <table class="data-table">
          <thead><tr><th>When</th><th>IP</th><th>Method</th><th>Path</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($requests as $q): ?>
            <tr>
              <td class="mono"><?= ts($q['createdAt'] ?? null) ?></td>
              <td class="mono"><?= e($q['ip'] ?? '') ?></td>
              <td><?= e($q['method'] ?? '') ?></td>
              <td class="mono"><?= e($q['path'] ?? '') ?></td>
              <td><?= (int) ($q['statusCode'] ?? 0) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
