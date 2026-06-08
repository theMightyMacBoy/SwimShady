<?php
require __DIR__ . '/../src/bootstrap.php';
$me = require_admin();
$csrf = csrf_token();
$appName = config('app')['name'] ?? 'Swim Shady';

$ROLES = ['admin', 'standard', 'readonly'];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Session expired — please try again.';
    } else {
        $do = $_POST['do'] ?? '';
        $devices = array_map('intval', $_POST['devices'] ?? []);

        if ($do === 'add') {
            $body = [
                'username'  => trim($_POST['username'] ?? ''),
                'password'  => $_POST['password'] ?? '',
                'role'      => $_POST['role'] ?? 'standard',
                'deviceIds' => $devices,
            ];
            $res = api_post('/api/users', $body, $me['jwt']);
            if ($res['status'] === 200) {
                $_SESSION['flash_msg'] = 'User created.';
                redirect('users.php');
            }
            $error = $res['data']['error'] ?? 'Could not create user.';
        } elseif ($do === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            $body = ['role' => $_POST['role'] ?? '', 'deviceIds' => $devices];
            if (($_POST['password'] ?? '') !== '') {
                $body['password'] = $_POST['password'];
            }
            $res = api_put('/api/users/' . $id, $body, $me['jwt']);
            if ($res['status'] === 200) {
                $_SESSION['flash_msg'] = 'User updated.';
                redirect('users.php');
            }
            $error = $res['data']['error'] ?? 'Could not update user.';
        } elseif ($do === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $res = api_delete('/api/users/' . $id, $me['jwt']);
            if ($res['status'] === 204 || $res['status'] === 200) {
                $_SESSION['flash_msg'] = 'User deleted.';
                redirect('users.php');
            }
            $error = $res['data']['error'] ?? 'Could not delete user.';
        }
    }
}

$flash = $_SESSION['flash_msg'] ?? null;
unset($_SESSION['flash_msg']);

$users   = is_array(($r = api_get('/api/users', $me['jwt']))['data'] ?? null) ? $r['data'] : [];
$devices = is_array(($d = api_get('/api/devices', $me['jwt']))['data'] ?? null) ? $d['data'] : [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($appName) ?> — Users</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <header class="topbar">
    <h1>Users</h1>
    <div class="topbar-right">
      <a class="logout" href="devices.php">Devices</a>
      <a class="logout" href="firmware.php">Firmware</a>
      <a class="logout" href="admin-ips.php">IP Security</a>
      <a class="logout" href="index.php">← Dashboard</a>
    </div>
  </header>

  <main class="history-main">
    <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
    <?php if ($flash): ?><p class="success"><?= e($flash) ?></p><?php endif; ?>

    <div class="card">
      <div class="label">Existing users</div>
      <p class="muted">Admins see/control all devices. Standard users control, and read-only users only view, the devices checked below.</p>

      <?php foreach ($users as $u): ?>
        <?php $assigned = array_map('intval', $u['deviceIds'] ?? []); ?>
        <form method="post" class="user-row">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="do" value="update">
          <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
          <div class="user-head">
            <strong><?= e($u['username']) ?></strong>
            <select name="role" class="device-select">
              <?php foreach ($ROLES as $r): ?>
                <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= $r ?></option>
              <?php endforeach; ?>
            </select>
            <input type="password" name="password" class="inline-input" placeholder="reset password (optional)" autocomplete="new-password">
            <span class="muted">last login: <?= e($u['lastLoginAt'] ?? 'never') ?></span>
          </div>
          <div class="device-assign">
            <?php if (!$devices): ?>
              <span class="muted">No devices registered yet.</span>
            <?php else: foreach ($devices as $d): ?>
              <label class="chk">
                <input type="checkbox" name="devices[]" value="<?= (int) $d['id'] ?>"
                  <?= in_array((int) $d['id'], $assigned, true) ? 'checked' : '' ?>>
                <?= e($d['name']) ?>
              </label>
            <?php endforeach; endif; ?>
            <span class="muted assign-note">(admins see all devices regardless)</span>
          </div>
          <div class="row-actions">
            <button type="submit" class="btn-secondary">Save</button>
          </div>
          <button type="submit" form="del-<?= (int) $u['id'] ?>" class="btn-danger">Delete</button>
        </form>
        <form id="del-<?= (int) $u['id'] ?>" method="post"
              onsubmit="return confirm('Delete user <?= e($u['username']) ?>?');" hidden>
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="do" value="delete">
          <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
        </form>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <div class="label">Add user</div>
      <form method="post" class="user-row">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="do" value="add">
        <div class="user-head">
          <input type="text" name="username" class="inline-input" placeholder="username" required>
          <input type="password" name="password" class="inline-input" placeholder="password (min 8)" required minlength="8" autocomplete="new-password">
          <select name="role" class="device-select">
            <option value="standard">standard</option>
            <option value="readonly">readonly</option>
            <option value="admin">admin</option>
          </select>
        </div>
        <div class="device-assign">
          <?php foreach ($devices as $d): ?>
            <label class="chk"><input type="checkbox" name="devices[]" value="<?= (int) $d['id'] ?>"> <?= e($d['name']) ?></label>
          <?php endforeach; ?>
        </div>
        <div class="row-actions"><button type="submit">Add user</button></div>
      </form>
    </div>
  </main>
</body>
</html>
