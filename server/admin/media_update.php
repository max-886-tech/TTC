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
  $item = media_update_item($path, [
    'basename' => trim((string)($_POST['basename'] ?? '')),
    'title' => trim((string)($_POST['title'] ?? '')),
    'alt' => trim((string)($_POST['alt'] ?? '')),
    'caption' => trim((string)($_POST['caption'] ?? '')),
    'description' => trim((string)($_POST['description'] ?? '')),
  ]);
  json_out(['ok' => true, 'item' => $item], 200);
} catch (Throwable $e) {
  json_out(['ok' => false, 'error' => APP_DEBUG ? $e->getMessage() : 'Unable to update media'], 400);
}
