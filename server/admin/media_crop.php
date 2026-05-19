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
$x = (int)($_POST['x'] ?? 0);
$y = (int)($_POST['y'] ?? 0);
$w = (int)($_POST['w'] ?? 0);
$h = (int)($_POST['h'] ?? 0);

if ($w <= 0 || $h <= 0) {
  json_out(['ok' => false, 'error' => 'Invalid crop area'], 400);
}

try {
  $item = media_crop_image($path, $x, $y, $w, $h);
  json_out(['ok' => true, 'item' => $item], 200);
} catch (Throwable $e) {
  json_out(['ok' => false, 'error' => APP_DEBUG ? $e->getMessage() : 'Unable to crop image'], 400);
}
