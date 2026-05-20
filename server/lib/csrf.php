<?php
require_once __DIR__ . '/session.php';

function csrf_token(): string {
  start_secure_session();
  if (empty($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['_csrf'];
}

function csrf_input(): string {
  $t = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
  return '<input type="hidden" name="_csrf" value="' . $t . '">';
}

function csrf_verify(): void {
  start_secure_session();
  $sent = $_POST['_csrf'] ?? '';
  if (!$sent || empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $sent)) {
    http_response_code(403);
    echo "CSRF check failed.";
    exit;
  }
}
