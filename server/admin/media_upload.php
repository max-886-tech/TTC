<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/media.php';

require_login();
if (!(can('quiz.manage') || can('exams.manage'))) {
  json_out(['location' => '', 'error' => 'forbidden'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_out(['location' => '', 'error' => 'method_not_allowed'], 405);
}

try {
  $file = $_FILES['file'] ?? $_FILES['image'] ?? null;
  if (!is_array($file)) {
    json_out(['location' => '', 'error' => 'no_file'], 400);
  }
  $url = media_save_uploaded_image($file, 'media');
  json_out(['location' => $url], 200);
} catch (Throwable $e) {
  json_out(['location' => '', 'error' => APP_DEBUG ? $e->getMessage() : 'Upload failed'], 400);
}
