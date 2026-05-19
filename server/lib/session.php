<?php
require_once __DIR__ . '/../config.php';

function is_https(): bool {
  if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
  if (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) return true;
  return false;
}

function start_secure_session(): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;

  $secure = is_https();

  session_name(SESSION_NAME);
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);

  session_start();

  // Idle timeout
  $now = time();
  if (!isset($_SESSION['_last_activity'])) {
    $_SESSION['_last_activity'] = $now;
  } else {
    if (($now - (int)$_SESSION['_last_activity']) > SESSION_TTL_SECONDS) {
      session_unset();
      session_destroy();
      session_start();
    }
    $_SESSION['_last_activity'] = $now;
  }
}
