<?php
// bootstrap.php - shared app bootstrap (Phase 1)
declare(strict_types=1);

define('APP_ROOT', __DIR__);
// Polyfills (PHP < 8)
if (!function_exists('str_ends_with')) {
  function str_ends_with(string $haystack, string $needle): bool {
    if ($needle === '') return true;
    return substr($haystack, -strlen($needle)) === $needle;
  }
}

// Config + core libs (keep existing lib functions for backward compatibility)
require_once APP_ROOT . '/config.php';
require_once APP_ROOT . '/lib/helpers.php';
require_once APP_ROOT . '/lib/db.php';
require_once APP_ROOT . '/lib/session.php';
require_once APP_ROOT . '/lib/auth.php';
require_once APP_ROOT . '/lib/rbac.php';
require_once APP_ROOT . '/lib/csrf.php';
require_once APP_ROOT . '/lib/audit.php';
require_once APP_ROOT . '/lib/r2.php';
require_once APP_ROOT . '/lib/quiz.php';

// Simple PSR-4 style autoloader for App\*
spl_autoload_register(function(string $class): void {
  $prefix = 'App\\';
  if (strpos($class, $prefix) !== 0) return;
  $rel = substr($class, strlen($prefix));
  $path = APP_ROOT . '/app/' . str_replace('\\', '/', $rel) . '.php';
  if (is_file($path)) require_once $path;
});

// Phase 4/7: Run DB migrations only when explicitly enabled
if (APP_RUN_MIGRATIONS) try {
  \App\Migrations\Migrator::run(db(), APP_ROOT . '/migrations');
  define('APP_MIGRATIONS_RAN', true);
} catch (Throwable $e) {
  // Fail loudly in admin, but avoid leaking sensitive details to public.
  if (strpos($_SERVER['REQUEST_URI'] ?? '', '/admin/') !== false) {
    http_response_code(500);
    echo '<h3>Database migration failed</h3>';
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
    exit;
  }
  // For public routes, continue; some pages may still work.
}

// Simple view renderer (Phase 3)
function view(string $template, array $data = []): void {
  $rel = ltrim($template, '/');
  // Support both "app/Views" and "app/views" (case-sensitive filesystems + mixed deploy tooling)
  $candidates = [
    APP_ROOT . '/app/Views/' . $rel,
    APP_ROOT . '/app/views/' . $rel,
  ];

  $file = '';
  foreach ($candidates as $cand) {
    if (!str_ends_with($cand, '.php')) $cand .= '.php';
    if (is_file($cand)) { $file = $cand; break; }
  }

  if ($file === '') {
    http_response_code(500);
    echo "View not found. Tried:\n" . htmlspecialchars(implode("\n", array_map(function($p){
      return str_ends_with($p, '.php') ? $p : ($p . '.php');
    }, $candidates)));
    exit;
  }
  extract($data, EXTR_SKIP);
  require $file;
}