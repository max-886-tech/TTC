<?php
require_once __DIR__ . '/../lib/db.php';

// ---------- Rate limit settings ----------
const RATE_WINDOW_SECONDS = 600;    // 10 minutes
const MAX_FAILS_PER_WINDOW = 8;     // failed attempts allowed per IP+dumps per window
const MAX_TOTAL_PER_WINDOW = 30;    // total attempts allowed per IP+dumps per window

// ---------- App version policy ----------
const LATEST_VERSION = '1.0.0'; // update when you release
const MIN_VERSION    = '1.0.0'; // raise to block old builds
const FORCE_UPDATE   = true;   // true = everyone must update to latest
const UPDATE_URL     = 'https://thetruecerts.com/reader_1.0.0.zip';

function clean_str($s, $max) {
  $s = trim((string)$s);
  if (strlen($s) > $max) $s = substr($s, 0, $max);
  return $s;
}

function add_version_fields(array $payload): array {
  $payload['latest_version'] = LATEST_VERSION;
  $payload['min_version']    = MIN_VERSION;
  $payload['force_update']   = FORCE_UPDATE;
  $payload['update_url']     = UPDATE_URL;
  return $payload;
}

function get_client_ip() {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
  // If behind Cloudflare/proxy you control, you can enable ONE:
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
  json_out(add_version_fields([
'ok' => false, 'error' => 'method_not_allowed'
]), 405);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;

$exam_id   = clean_str($data['exam_id'] ?? '', 64);
$code      = clean_str($data['code'] ?? '', 64);
$device_id = clean_str($data['device_id'] ?? '', 128);

if ($exam_id === '' || $code === '' || $device_id === '') {
  json_out(add_version_fields([
'ok' => false,
    'error' => 'bad_request',
    'message' => 'Missing dumps code, code, or device_id.',
    'server_time_utc' => utc_now_sql(),
]), 400);
}

$pdo = db();
$ip = get_client_ip();

// Cleanup old attempts
$cutoff = (new DateTime('now', new DateTimeZone('UTC')))
  ->modify('-' . RATE_WINDOW_SECONDS . ' seconds')
  ->format('Y-m-d H:i:s');
cleanup_attempts($pdo, $cutoff);

$counts = count_attempts($pdo, $ip, $exam_id, $cutoff);
$fails = (int)($counts['fails'] ?? 0);
$total = (int)($counts['total'] ?? 0);

if ($fails >= MAX_FAILS_PER_WINDOW || $total >= MAX_TOTAL_PER_WINDOW) {
  json_out(add_version_fields([
'ok' => false,
    'error' => 'rate_limited',
    'message' => 'Too many attempts. Try again later.',
    'server_time_utc' => utc_now_sql(),
]), 429);
}

$codeHash = code_hash($code);
$devHash  = device_hash($device_id);

try {
  $pdo->beginTransaction();

  // Lock row
  $sel = $pdo->prepare("
    SELECT * FROM access_codes
    WHERE exam_id=? AND code_hash=?
    LIMIT 1
    FOR UPDATE
  ");
  $sel->execute([$exam_id, $codeHash]);
  $row = $sel->fetch();

  if (!$row) {
    $pdo->commit();
    log_attempt($pdo, $ip, $exam_id, false);
    usleep(150000);
    json_out(add_version_fields([
'ok' => false,
      'error' => 'invalid_code',
      'message' => 'Invalid password/code.',
      'server_time_utc' => utc_now_sql(),
]), 403);
  }

  if ((int)$row['is_active'] !== 1) {
    $pdo->commit();
    log_attempt($pdo, $ip, $exam_id, false);
    json_out(add_version_fields([
'ok' => false,
      'error' => 'inactive',
      'message' => 'Code is inactive.',
      'server_time_utc' => utc_now_sql(),
]), 403);
  }

  // Expiry check (UTC)
  if (!empty($row['expires_at'])) {
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $exp = new DateTime($row['expires_at'], new DateTimeZone('UTC'));
    if ($now > $exp) {
      $pdo->commit();
      log_attempt($pdo, $ip, $exam_id, false);
      json_out(add_version_fields([
'ok' => false,
        'error' => 'expired',
        'message' => 'Code expired.',
        'expires_at' => $row['expires_at'],      // NEW
        'expires_at_utc' => $row['expires_at'],  // keep old
        'server_time_utc' => utc_now_sql(),
]), 403);
    }
  }

  $max_uses = max(1, (int)($row['max_uses'] ?? 1));
  $used_count = (int)($row['used_count'] ?? 0);
  $bound_now = false;
  $rebound = false;
  $ts = utc_now_sql();

  if (empty($row['device_hash'])) {
    if ($used_count >= $max_uses) {
      $pdo->commit();
      log_attempt($pdo, $ip, $exam_id, false);
      json_out(add_version_fields([
'ok' => false,
        'error' => 'max_uses_reached',
        'message' => 'No activations left for this code.',
        'server_time_utc' => utc_now_sql(),
]), 403);
    }

    $upd = $pdo->prepare("
      UPDATE access_codes
      SET device_hash = ?,
          first_used_at = COALESCE(first_used_at, ?),
          last_used_at  = ?,
          used_count    = used_count + 1
      WHERE id = ?
    ");
    $upd->execute([$devHash, $ts, $ts, $row['id']]);
    $bound_now = true;
  } else {
    if (!hash_equals($row['device_hash'], $devHash)) {
      if ($used_count >= $max_uses) {
        $pdo->commit();
        log_attempt($pdo, $ip, $exam_id, false);
        json_out(add_version_fields([
'ok' => false,
          'error' => 'device_mismatch',
          'message' => 'This code is locked to another device.',
          'server_time_utc' => utc_now_sql(),
]), 403);
      }

      // Rebind (consumes one activation)
      $upd = $pdo->prepare("
        UPDATE access_codes
        SET device_hash = ?,
            last_used_at = ?,
            used_count = used_count + 1
        WHERE id = ?
      ");
      $upd->execute([$devHash, $ts, $row['id']]);
      $bound_now = true;
      $rebound = true;
    } else {
      $upd = $pdo->prepare("UPDATE access_codes SET last_used_at=? WHERE id=?");
      $upd->execute([$ts, $row['id']]);
    }
  }

  $pdo->commit();
  log_attempt($pdo, $ip, $exam_id, true);

  // ✅ NEW FIELDS for watermark + UI
  $exam_name    = $row['exam_name'] ?? null;
  $user_name    = $row['user_name'] ?? null;
  $user_email   = $row['user_email'] ?? null;
  $user_phone   = $row['user_phone'] ?? null;
  $user_address = $row['user_address'] ?? null;

  json_out(add_version_fields([
'ok' => true,
    'message' => 'ok',
    'exam_id' => $exam_id,
    'exam_name' => $exam_name,
    'dump_id' => $exam_id,
    'dump_name' => $exam_name,
    'user_name' => $user_name,
    'user_email' => $user_email,
    'user_phone' => $user_phone,
    'user_address' => $user_address,

    'bound_now' => $bound_now,
    'rebound' => $rebound,
    'used_count' => $used_count + ($bound_now ? 1 : 0),
    'max_uses' => $max_uses,

    'expires_at' => $row['expires_at'] ?? null,       // NEW (what your C++ parses)
    'expires_at_utc' => $row['expires_at'] ?? null,   // keep old
    'server_time_utc' => utc_now_sql(),
]), 200);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  log_attempt($pdo, $ip, $exam_id, false);

  json_out(add_version_fields([
'ok' => false,
    'error' => 'server_error',
    'message' => APP_DEBUG ? ($e->getMessage()) : 'Server error.',
    'server_time_utc' => utc_now_sql(),
]), 500);
}
