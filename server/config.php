<?php
// config.php
// Production-safe configuration. Values come from environment first, with local defaults as fallback.

require_once __DIR__ . '/lib/env.php';
enbl_env_load(__DIR__);

if (!defined('APP_ENV')) define('APP_ENV', (string) enbl_env('APP_ENV', 'production'));
if (!defined('APP_NAME')) define('APP_NAME', (string) enbl_env('APP_NAME', 'TTC License Admin'));

// Database (PDO)
if (!defined('DB_DSN'))  define('DB_DSN',  (string) enbl_env('DB_DSN', 'mysql:host=localhost;dbname=u487923957_access;charset=utf8mb4'));
if (!defined('DB_USER')) define('DB_USER', (string) enbl_env('DB_USER', 'u487923957_access'));
if (!defined('DB_PASS')) define('DB_PASS', (string) enbl_env('DB_PASS', 'CHANGE_ME_DB_PASS'));

// Secrets
if (!defined('CODE_PEPPER')) define('CODE_PEPPER', (string) enbl_env('CODE_PEPPER', 'CHANGE_ME_CODE_PEPPER'));
if (!defined('DEVICE_PEPPER')) define('DEVICE_PEPPER', (string) enbl_env('DEVICE_PEPPER', 'CHANGE_ME_DEVICE_PEPPER'));
if (!defined('TTC_CONTENT_PASSWORD')) define('TTC_CONTENT_PASSWORD', (string) enbl_env('TTC_CONTENT_PASSWORD', '-ddG^|cE;8+Yp8&&'));
if (!defined('TTC_PBKDF2_ITERATIONS')) define('TTC_PBKDF2_ITERATIONS', (int) enbl_env('TTC_PBKDF2_ITERATIONS', 100000));

// Session security
if (!defined('SESSION_NAME')) define('SESSION_NAME', (string) enbl_env('SESSION_NAME', 'ttc_admin'));
if (!defined('SESSION_TTL_SECONDS')) define('SESSION_TTL_SECONDS', (int) enbl_env('SESSION_TTL_SECONDS', 60 * 60 * 8));

// Default license code format
if (!defined('CODE_PREFIX')) define('CODE_PREFIX', (string) enbl_env('CODE_PREFIX', 'TTC'));

// App flags
if (!defined('APP_DEBUG')) define('APP_DEBUG', (bool) enbl_env('APP_DEBUG', APP_ENV !== 'production'));
if (!defined('APP_RUN_MIGRATIONS')) define('APP_RUN_MIGRATIONS', (bool) enbl_env('APP_RUN_MIGRATIONS', false));
if (!defined('QUIZ_REVIEW_BASE_URL')) define('QUIZ_REVIEW_BASE_URL', (string) enbl_env('QUIZ_REVIEW_BASE_URL', 'https://thetruecerts.com'));

if (APP_DEBUG) {
  ini_set('display_errors', '1');
  ini_set('display_startup_errors', '1');
  error_reporting(E_ALL);
} else {
  ini_set('display_errors', '0');
  ini_set('display_startup_errors', '0');
  error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
}

// -----------------------------
// Cloudflare R2 (S3-compatible)
// -----------------------------
if (!defined('R2_BUCKET')) define('R2_BUCKET', (string) enbl_env('R2_BUCKET', 'ttc'));
if (!defined('R2_ENDPOINT')) define('R2_ENDPOINT', (string) enbl_env('R2_ENDPOINT', 'https://e84ff8934a77dbb37b11fedddeaa28bc.r2.cloudflarestorage.com'));
if (!defined('R2_REGION')) define('R2_REGION', (string) enbl_env('R2_REGION', 'auto'));
if (!defined('R2_PUBLIC_BASE')) define('R2_PUBLIC_BASE', (string) enbl_env('R2_PUBLIC_BASE', 'https://thetruecerts.com'));
if (!defined('R2_ACCESS_KEY')) define('R2_ACCESS_KEY', (string) enbl_env('R2_ACCESS_KEY', 'CHANGE_ME_R2_ACCESS_KEY'));
if (!defined('R2_SECRET_KEY')) define('R2_SECRET_KEY', (string) enbl_env('R2_SECRET_KEY', 'CHANGE_ME_R2_SECRET_KEY'));

// Helper: JSON output
if (!function_exists('json_out')) {
  function json_out($arr, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_SLASHES);
    exit;
  }
}
