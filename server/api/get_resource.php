<?php
// api/get_resource.php
// Input: code (+ optional device_id)
// Output: resource_type = enbl|quiz and next action.
// - enbl: tells client to call api/get_download.php (or returns r2 url if desired)
// - quiz: returns start_url for web UI + exam info

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/quiz.php';

function clean_str($s, $max) {
  $s = trim((string)$s);
  if (strlen($s) > $max) $s = substr($s, 0, $max);
  return $s;
}

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_out(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;

$code      = clean_str($data['code'] ?? '', 64);
$device_id = clean_str($data['device_id'] ?? '', 128);

if ($code === '') {
  json_out(['ok' => false, 'error' => 'bad_request', 'message' => 'Missing code.', 'server_time_utc' => utc_now_sql()], 400);
}

$pdo = db();
quiz_safe_ensure_schema($pdo);

$codeHash = code_hash($code);

// Find code row by code_hash
$sel = $pdo->prepare("SELECT * FROM access_codes WHERE code_hash=? ORDER BY is_active DESC, id DESC LIMIT 1");
$sel->execute([$codeHash]);
$row = $sel->fetch();

if (!$row) {
  json_out(['ok' => false, 'error' => 'invalid_code', 'message' => 'Invalid access code.', 'server_time_utc' => utc_now_sql()], 403);
}

if ((int)$row['is_active'] !== 1) {
  json_out(['ok' => false, 'error' => 'inactive', 'message' => 'Code is inactive.', 'server_time_utc' => utc_now_sql()], 403);
}

// Expiry check (UTC)
if (!empty($row['expires_at'])) {
  $now = new DateTime('now', new DateTimeZone('UTC'));
  $exp = new DateTime($row['expires_at'], new DateTimeZone('UTC'));
  if ($now > $exp) {
    json_out([
      'ok' => false,
      'error' => 'expired',
      'message' => 'Code expired.',
      'expires_at' => $row['expires_at'],
      'server_time_utc' => utc_now_sql(),
    ], 403);
  }
}

// Device binding (same rules as download)
if ($device_id !== '') {
  $devHash = device_hash($device_id);
  $ts = utc_now_sql();

  try {
    $pdo->beginTransaction();

    $lock = $pdo->prepare("SELECT * FROM access_codes WHERE id=? LIMIT 1 FOR UPDATE");
    $lock->execute([(int)$row['id']]);
    $row2 = $lock->fetch();
    if (!$row2) {
      $pdo->rollBack();
      json_out(['ok' => false, 'error' => 'not_found', 'message' => 'Code not found.', 'server_time_utc' => utc_now_sql()], 404);
    }

    $max_users  = max(1, (int)($row2['max_users'] ?? 1));
    $used_count = (int)($row2['used_count'] ?? 0);

    if (empty($row2['device_hash'])) {
      if ($used_count >= $max_users) {
        $pdo->rollBack();
        json_out(['ok' => false, 'error' => 'max_users_reached', 'message' => 'No user activations left for this code.', 'server_time_utc' => utc_now_sql()], 403);
      }
      $upd = $pdo->prepare("UPDATE access_codes SET device_hash=?, first_used_at=COALESCE(first_used_at, ?), last_used_at=?, used_count=used_count+1 WHERE id=?");
      $upd->execute([$devHash, $ts, $ts, (int)$row2['id']]);
    } else {
      if (!hash_equals($row2['device_hash'], $devHash)) {
        if ($used_count >= $max_users) {
          $pdo->rollBack();
          json_out(['ok' => false, 'error' => 'device_mismatch', 'message' => 'This code is locked to another device.', 'server_time_utc' => utc_now_sql()], 403);
        }
        $upd = $pdo->prepare("UPDATE access_codes SET device_hash=?, last_used_at=?, used_count=used_count+1 WHERE id=?");
        $upd->execute([$devHash, $ts, (int)$row2['id']]);
      } else {
        $upd = $pdo->prepare("UPDATE access_codes SET last_used_at=? WHERE id=?");
        $upd->execute([$ts, (int)$row2['id']]);
      }
    }

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out(['ok' => false, 'error' => 'server_error', 'message' => APP_DEBUG ? $e->getMessage() : 'Server error.', 'server_time_utc' => utc_now_sql()], 500);
  }
}

$exam_id = (string)($row['exam_id'] ?? '');
$exam_name = (string)($row['exam_name'] ?? $exam_id);

$exam = quiz_get_exam($pdo, $exam_id);
if (!$exam) {
  // Backward compat: if the code row has r2_key it's TTC, else default to QUIZ
  $guess_type = (!empty($row['r2_key']) || !empty($row['r2_url'])) ? 'enbl' : 'quiz';
  quiz_upsert_exam($pdo, $exam_id, $exam_name, [
    'resource_type' => $guess_type,
    'duration_minutes' => 30,
    'shuffle_questions' => 1,
    'shuffle_choices' => 1,
  ]);
  $exam = quiz_get_exam($pdo, $exam_id);
}

$resource_type = (string)($exam['resource_type'] ?? 'enbl');

if ($resource_type === 'quiz') {
  json_out([
    'ok' => true,
    'resource_type' => 'quiz',
    'exam_id' => $exam_id,
    'exam_name' => $exam_name,
    'start_url' => '/public/quiz/attempt.php?code=' . urlencode($code),
    'server_time_utc' => utc_now_sql(),
  ], 200);
}

// TTC
json_out([
  'ok' => true,
  'resource_type' => 'enbl',
  'exam_id' => $exam_id,
  'exam_name' => $exam_name,
  'next' => 'call_get_download',
  'download_api' => '/api/get_download.php',
  'server_time_utc' => utc_now_sql(),
], 200);
