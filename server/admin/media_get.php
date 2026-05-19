<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/media.php';

require_login();
if (!(can('quiz.manage') || can('exams.manage'))) {
  json_out(['ok' => false, 'error' => 'forbidden'], 403);
}

$path = trim((string)($_GET['path'] ?? ''));
try {
  json_out(['ok' => true, 'item' => media_get_item($path)], 200);
} catch (Throwable $e) {
  json_out(['ok' => false, 'error' => APP_DEBUG ? $e->getMessage() : 'Unable to load media item'], 400);
}
