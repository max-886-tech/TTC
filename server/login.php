<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/helpers.php';

start_secure_session();
$err = '';

// simple login rate limit (per session)
$fails = (int)($_SESSION['_login_fails'] ?? 0);
$blocked_until = (int)($_SESSION['_login_blocked_until'] ?? 0);
if ($blocked_until > time()) {
  $err = "Too many attempts. Try again in " . ($blocked_until - time()) . " seconds.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $err === '') {
  csrf_verify();
  $username = $_POST['username'] ?? '';
  $password = $_POST['password'] ?? '';

  if (login_user($username, $password)) {
    $_SESSION['_login_fails'] = 0;
    $_SESSION['_login_blocked_until'] = 0;
    header("Location: /admin/");
    exit;
  } else {
    $fails++;
    $_SESSION['_login_fails'] = $fails;
    if ($fails >= 8) {
      $_SESSION['_login_blocked_until'] = time() + 60; // 1 minute
      $err = "Too many attempts. Try again in 60 seconds.";
    } else {
      $err = "Invalid username or password.";
    }
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h(APP_NAME) ?> - Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container" style="max-width: 420px; margin-top: 10vh;">
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h4 class="mb-3"><?= h(APP_NAME) ?></h4>
      <?php if ($err): ?>
        <div class="alert alert-danger"><?= h($err) ?></div>
      <?php endif; ?>
      <form method="post" autocomplete="off">
        <?= csrf_input() ?>
        <div class="mb-3">
          <label class="form-label">Username</label>
          <input class="form-control" name="username" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Password</label>
          <input class="form-control" type="password" name="password" required>
        </div>
        <button class="btn btn-primary w-100" type="submit">Login</button>
      </form>
    </div>
  </div>
  <div class="text-muted small mt-3">
    API endpoint: <code>/api/validate.php</code>
  </div>
</div>
</body>
</html>
