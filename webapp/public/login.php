<?php
require __DIR__ . '/../src/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Session expired — please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if (attempt_login($username, $password)) {
            redirect('index.php');
        }
        $error = 'Invalid username or password.';
    }
}

$csrf = csrf_token();
$appName = config('app')['name'] ?? 'Swim Shady';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($appName) ?> — Sign in</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-body">
  <form class="login-card" method="post" action="login.php">
    <h1><?= e($appName) ?></h1>
    <?php if ($error): ?>
      <p class="error"><?= e($error) ?></p>
    <?php endif; ?>
    <label>Username
      <input type="text" name="username" autocomplete="username" required autofocus>
    </label>
    <label>Password
      <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <button type="submit">Sign in</button>
    <button type="button" id="tryDemo" class="btn-secondary">Try the live demo</button>
    <p class="muted demo-hint">The demo signs in as <strong>demo</strong> / <strong>demo</strong> and opens a simulated pool you can fully control.</p>
  </form>
  <script>
    // Prefill the public demo credentials and submit.
    document.getElementById('tryDemo').addEventListener('click', function () {
      var f = this.form;
      f.username.value = 'demo';
      f.password.value = 'demo';
      f.submit();
    });
  </script>
</body>
</html>
