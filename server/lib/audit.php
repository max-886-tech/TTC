<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function client_ip_safe(): string {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  if (strlen($ip) > 64) $ip = substr($ip, 0, 64);
  return $ip;
}

function audit_log_event(string $action, string $target_type, $target_id = null, array $meta = []): void {
  try {
    $pdo = db();
    $u = current_user();
    $actor = $u ? (int)$u['id'] : null;

    $stmt = $pdo->prepare("
      INSERT INTO audit_log (actor_user_id, action, target_type, target_id, ip, meta_json)
      VALUES (?,?,?,?,?,?)
    ");
    $stmt->execute([
      $actor,
      $action,
      $target_type,
      $target_id !== null ? (int)$target_id : null,
      client_ip_safe(),
      $meta ? json_encode($meta, JSON_UNESCAPED_SLASHES) : null
    ]);
  } catch (Throwable $e) {
    // Never break app if audit fails
  }
}
