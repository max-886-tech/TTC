<?php
// api/get_download.php
// EXE calls this BEFORE opening an .ttc file.
// It validates the access code (device binding + expiry + max uses) and returns a short-lived download URL.

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/r2.php';

// ---------- Rate limit settings ----------
const RATE_WINDOW_SECONDS = 600;    // 10 minutes
const MAX_FAILS_PER_WINDOW = 10;    // failed attempts allowed per IP+dumps per window
const MAX_TOTAL_PER_WINDOW = 60;    // total attempts allowed per IP+dumps per window

function clean_str($s, $max) {
  $s = trim((string)$s);
  if (strlen($s) > $max) $s = substr($s, 0, $max);
  return $s;
}

function get_client_ip() {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
  // If behind Cloudflare/proxy you control, enable ONE:
  // if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
  // if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
  if (strlen($ip) > 64) $ip = substr($ip, 0, 64);
  return $ip;
}

function cleanup_attempts(PDO $pdo, $cutoff) {
  $del = $pdo->prepare("DELETE FROM license_attempts WHERE created_at < ?");
  $del->execute([$cutoff]);
}

function count_attempts(PDO $pdo, $ip, $exam_id, $cutoff) {
  $stmt = $pdo->prepare("
    SELECT
      SUM(CASE WHEN ok=0 THEN 1 ELSE 0 END) AS fails,
      COUNT(*) AS total
    FROM license_attempts
    WHERE ip=? AND exam_id=? AND created_at >= ?
  ");
  $stmt->execute([$ip, $exam_id, $cutoff]);
  return $stmt->fetch() ?: ['fails' => 0, 'total' => 0];
}

function log_attempt(PDO $pdo, $ip, $exam_id, $ok) {
  $ins = $pdo->prepare("INSERT INTO license_attempts (ip, exam_id, ok) VALUES (?,?,?)");
  $ins->execute([$ip, $exam_id, $ok ? 1 : 0]);
}

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_out(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

// Accept JSON or form POST
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;

$code      = clean_str($data['code'] ?? '', 64);
$device_id = clean_str($data['device_id'] ?? '', 128);

if ($code === '') {
  json_out(['ok' => false, 'error' => 'bad_request', 'message' => 'Missing code.', 'server_time_utc' => utc_now_sql()], 400);
}

$pdo = db();
$ip  = get_client_ip();

// Unknown exam_id for rate limit when code invalid
$rate_exam_id = 'UNKNOWN';

// Find code row by code_hash
$codeHash = code_hash($code);

$sel = $pdo->prepare("
  SELECT *
  FROM access_codes
  WHERE code_hash=?
  ORDER BY is_active DESC, id DESC
  LIMIT 1
");
$sel->execute([$codeHash]);
$row = $sel->fetch();

if ($row) {
  $rate_exam_id = (string)($row['exam_id'] ?? 'UNKNOWN');
}

// Cleanup old attempts
$cutoff = (new DateTime('now', new DateTimeZone('UTC')))
  ->modify('-' . RATE_WINDOW_SECONDS . ' seconds')
  ->format('Y-m-d H:i:s');
cleanup_attempts($pdo, $cutoff);

$counts = count_attempts($pdo, $ip, $rate_exam_id, $cutoff);
$fails = (int)($counts['fails'] ?? 0);
$total = (int)($counts['total'] ?? 0);

if ($fails >= MAX_FAILS_PER_WINDOW || $total >= MAX_TOTAL_PER_WINDOW) {
  json_out([
    'ok' => false,
    'error' => 'rate_limited',
    'message' => 'Too many attempts. Try again later.',
    'server_time_utc' => utc_now_sql(),
  ], 429);
}

if (!$row) {
  log_attempt($pdo, $ip, $rate_exam_id, false);
  usleep(150000);
  json_out([
    'ok' => false,
    'error' => 'invalid_code',
    'message' => 'Invalid access code.',
    'server_time_utc' => utc_now_sql(),
  ], 403);
}

if ((int)$row['is_active'] !== 1) {
  log_attempt($pdo, $ip, $rate_exam_id, false);
  json_out([
    'ok' => false,
    'error' => 'inactive',
    'message' => 'Code is inactive.',
    'server_time_utc' => utc_now_sql(),
  ], 403);
}

// Expiry check (UTC)
if (!empty($row['expires_at'])) {
  $now = new DateTime('now', new DateTimeZone('UTC'));
  $exp = new DateTime($row['expires_at'], new DateTimeZone('UTC'));
  if ($now > $exp) {
    log_attempt($pdo, $ip, $rate_exam_id, false);
    json_out([
      'ok' => false,
      'error' => 'expired',
      'message' => 'Code expired.',
      'expires_at' => $row['expires_at'],
      'server_time_utc' => utc_now_sql(),
    ], 403);
  }
}

// Device binding (if device_id provided)
$max_uses   = max(1, (int)($row['max_uses'] ?? 1));
$used_count = (int)($row['used_count'] ?? 0);
$ts         = utc_now_sql();

if ($device_id !== '') {
  $devHash = device_hash($device_id);

  try {
    $pdo->beginTransaction();

    // Lock row again
    $lock = $pdo->prepare("SELECT * FROM access_codes WHERE id=? LIMIT 1 FOR UPDATE");
    $lock->execute([$row['id']]);
    $row2 = $lock->fetch();
    if (!$row2) {
      $pdo->rollBack();
      log_attempt($pdo, $ip, $rate_exam_id, false);
      json_out(['ok' => false, 'error' => 'not_found', 'message' => 'Code not found.', 'server_time_utc' => utc_now_sql()], 404);
    }

    $max_uses   = max(1, (int)($row2['max_uses'] ?? 1));
    $used_count = (int)($row2['used_count'] ?? 0);

    if (empty($row2['device_hash'])) {
      if ($used_count >= $max_uses) {
        $pdo->rollBack();
        log_attempt($pdo, $ip, $rate_exam_id, false);
        json_out(['ok' => false, 'error' => 'max_uses_reached', 'message' => 'No activations left for this code.', 'server_time_utc' => utc_now_sql()], 403);
      }

      $upd = $pdo->prepare("UPDATE access_codes SET device_hash=?, first_used_at=COALESCE(first_used_at, ?), last_used_at=?, used_count=used_count+1 WHERE id=?");
      $upd->execute([$devHash, $ts, $ts, $row2['id']]);
    } else {
      if (!hash_equals($row2['device_hash'], $devHash)) {
        if ($used_count >= $max_uses) {
          $pdo->rollBack();
          log_attempt($pdo, $ip, $rate_exam_id, false);
          json_out(['ok' => false, 'error' => 'device_mismatch', 'message' => 'This code is locked to another device.', 'server_time_utc' => utc_now_sql()], 403);
        }

        // Rebind consumes one activation
        $upd = $pdo->prepare("UPDATE access_codes SET device_hash=?, last_used_at=?, used_count=used_count+1 WHERE id=?");
        $upd->execute([$devHash, $ts, $row2['id']]);
      } else {
        $upd = $pdo->prepare("UPDATE access_codes SET last_used_at=? WHERE id=?");
        $upd->execute([$ts, $row2['id']]);
      }
    }

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    log_attempt($pdo, $ip, $rate_exam_id, false);
    json_out([
      'ok' => false,
      'error' => 'server_error',
      'message' => APP_DEBUG ? ($e->getMessage()) : 'Server error.',
      'server_time_utc' => utc_now_sql(),
    ], 500);
  }
}

// Build download URL
$r2Key = isset($row['r2_key']) ? trim((string)$row['r2_key']) : '';
$r2Url = isset($row['r2_url']) ? trim((string)$row['r2_url']) : '';

try {
  $url = '';
  if ($r2Key !== '') {
    $url = r2_presign_get_url($r2Key, 900); // 15 minutes
  } elseif ($r2Url !== '') {
    $url = $r2Url;
  }

  if ($url === '') {
    log_attempt($pdo, $ip, $rate_exam_id, false);
    json_out([
      'ok' => false,
      'error' => 'no_file',
      'message' => 'No R2 file linked to this code.',
      'server_time_utc' => utc_now_sql(),
    ], 404);
  }

  log_attempt($pdo, $ip, $rate_exam_id, true);
  json_out([
    'ok' => true,
    'url' => $url,
    'exam_id' => $row['exam_id'] ?? null,
    'exam_name' => $row['exam_name'] ?? null,
    'dump_id' => $row['exam_id'] ?? null,
    'dump_name' => $row['exam_name'] ?? null,
    'user_name' => $row['user_name'] ?? null,
    'server_time_utc' => utc_now_sql(),
  ], 200);

} catch (Throwable $e) {
  log_attempt($pdo, $ip, $rate_exam_id, false);
  json_out([
    'ok' => false,
    'error' => 'r2_error',
    'message' => APP_DEBUG ? ($e->getMessage()) : 'Download error.',
    'server_time_utc' => utc_now_sql(),
  ], 500);
}
