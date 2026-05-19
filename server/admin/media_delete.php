<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/media.php';
require_once __DIR__ . '/../lib/csrf.php';

require_login();
if (!(can('quiz.manage') || can('exams.manage'))) {
  json_out(['ok' => false, 'error' => 'forbidden'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_out(['ok' => false, 'error' => 'method_not_allowed'], 405);
}
csrf_verify();
$path = trim((string)($_POST['path'] ?? ''));
try {
  $deleted = media_delete_file($path);
  json_out(['ok' => true, 'deleted' => $deleted], 200);
} catch (Throwable $e) {
  json_out(['ok' => false, 'error' => APP_DEBUG ? $e->getMessage() : 'Delete failed'], 400);
}
