<?php
// config.php
// ✅ Update these values for your hosting
define('APP_NAME', 'TTC License Admin');

// Database (PDO)
define('DB_DSN',  'mysql:host=localhost;dbname=u487923957_access;charset=utf8mb4');
define('DB_USER', 'u487923957_access');
define('DB_PASS', 'Uu+$in8y$');

// Secret "pepper" for hashing license codes (keep only on server)
define('CODE_PEPPER',   '&g|?G.{_W}=>KjGZ');

// Secret used to hash device_id (can be different from CODE_PEPPER)
define('DEVICE_PEPPER', '$Cx3h6Uad4XNLt6Q');

// Session security
define('SESSION_NAME', 'ttc_admin');
define('SESSION_TTL_SECONDS', 60 * 60 * 8); // 8 hours

// Default license code format
define('CODE_PREFIX', 'TTC');

// Show PHP errors? (set false on production)
define('APP_DEBUG', true);

if (APP_DEBUG) {
  ini_set('display_errors', 1);
  ini_set('display_startup_errors', 1);
  error_reporting(E_ALL);
}

// -----------------------------
// Cloudflare R2 (S3-compatible)
// -----------------------------
// Bucket: ttc
// S3 API endpoint (base): https://e84ff8934a77dbb37b11fedddeaa28bc.r2.cloudflarestorage.com
// Public (custom domain): https://thetruecerts.com
//
// IMPORTANT:
// Put your Access Key + Secret Key here (or set environment variables with same names).
// Do NOT commit real keys to public repos.

define('R2_BUCKET', 'ttc');
define('R2_ENDPOINT', 'https://e84ff8934a77dbb37b11fedddeaa28bc.r2.cloudflarestorage.com');
define('R2_REGION', 'auto');
define('R2_PUBLIC_BASE', 'https://thetruecerts.com');

// TODO: set these values
define('R2_ACCESS_KEY', getenv('R2_ACCESS_KEY') ?: 'ff1a924448ba00dcbb1406198fed7a63');
define('R2_SECRET_KEY', getenv('R2_SECRET_KEY') ?: '8e87e872f733eb3753520e1e652ee96c5c8365d573dd797aaede6600580d0862');



// Helper: JSON output
if (!function_exists('json_out')) {
  function json_out($arr, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_SLASHES);
    exit;
  }
}
