<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

function current_user(): ?array {
  start_secure_session();
  if (empty($_SESSION['user_id'])) return null;

  $pdo = db();
  $stmt = $pdo->prepare("SELECT id, username, role, is_active FROM admin_users WHERE id=? LIMIT 1");
  $stmt->execute([$_SESSION['user_id']]);
  $u = $stmt->fetch();
  if (!$u || (int)$u['is_active'] !== 1) return null;
  return $u;
}

function require_login(): array {
  $u = current_user();
  if (!$u) {
    header("Location: /login.php");
    exit;
  }
  return $u;
}

function require_admin(): array {
  $u = require_login();
  if (($u['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo "Forbidden";
    exit;
  }
  return $u;
}

function login_user(string $username, string $password): bool {
  start_secure_session();

  $pdo = db();
  $stmt = $pdo->prepare("SELECT id, username, password_hash, role, is_active FROM admin_users WHERE username=? LIMIT 1");
  $stmt->execute([trim($username)]);
  $u = $stmt->fetch();

  if (!$u || (int)$u['is_active'] !== 1) return false;
  if (!password_verify($password, $u['password_hash'])) return false;

  session_regenerate_id(true);
  $_SESSION['user_id'] = (int)$u['id'];

  $pdo->prepare("UPDATE admin_users SET last_login_at=? WHERE id=?")->execute([utc_now_sql(), $u['id']]);
  return true;
}

function logout_user(): void {
  start_secure_session();
  $_SESSION = [];
  if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
      $params["path"], $params["domain"], $params["secure"], $params["httponly"]
    );
  }
  session_destroy();
}
