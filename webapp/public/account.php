<?php
require __DIR__ . '/../src/bootstrap.php';
$user = require_login();
$csrf = csrf_token();
$appName = config('app')['name'] ?? 'Swim Shady';

$error = null;
$ok = null;

// The demo account is public — its password is fixed (also enforced by the API).
$isDemo = ($user['username'] ?? '') === 'demo';

if (!$isDemo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Session expired — please try again.';
    } else {
        $new = $_POST['new'] ?? '';
        if (($_POST['confirm'] ?? '') !== $new) {
            $error = 'New passwords do not match.';
        } else {
            $res = api_post('/api/account/password', [
                'current' => $_POST['current'] ?? '',
                'new'     => $new,
            ], $user['jwt']);
            if ($res['status'] === 200) {
                $ok = 'Password updated.';
            } else {
                $error = $res['data']['error'] ?? 'Could not update password.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($appName) ?> — Account</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <header class="topbar">
    <h1>Account</h1>
    <div class="topbar-right">
      <a class="logout" href="index.php">← Dashboard</a>
    </div>
  </header>

  <main class="history-main">
    <div class="card" style="max-width:420px">
      <div class="label">Change password — <?= e($user['username']) ?></div>
      <?php if ($isDemo): ?>
        <p class="muted">This is the shared public demo account — its password can't be changed.</p>
      <?php else: ?>
      <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
      <?php if ($ok): ?><p class="success"><?= e($ok) ?></p><?php endif; ?>
      <form method="post" class="add-device" style="flex-direction:column;align-items:stretch">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Current password
          <input type="password" name="current" autocomplete="current-password" required>
        </label>
        <label>New password (min 8 chars)
          <input type="password" name="new" autocomplete="new-password" required minlength="8">
        </label>
        <label>Confirm new password
          <input type="password" name="confirm" autocomplete="new-password" required minlength="8">
        </label>
        <button type="submit">Update password</button>
      </form>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
