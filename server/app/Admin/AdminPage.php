<?php
declare(strict_types=1);

namespace App\Admin;

use PDO;

final class AdminPage {
  public static function boot(string $permission): PDO {
    require_once APP_ROOT . '/admin/_header.php';
    require_permission($permission);
    $pdo = db();
    quiz_safe_ensure_schema($pdo);
    return $pdo;
  }

  public static function finish(): void {
    require_once APP_ROOT . '/admin/_footer.php';
  }

  public static function queryString(array $current, array $overrides = []): string {
    $params = $current;
    foreach ($overrides as $k => $v) {
      if ($v === null || $v === '') unset($params[$k]);
      else $params[$k] = $v;
    }
    return '?' . http_build_query($params);
  }

  public static function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
  }

  public static function flash(string $key, string $message): void {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $_SESSION['admin_flash'][$key] = $message;
  }

  public static function pullFlash(string $key): ?string {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $msg = $_SESSION['admin_flash'][$key] ?? null;
    unset($_SESSION['admin_flash'][$key]);
    return is_string($msg) ? $msg : null;
  }
}
