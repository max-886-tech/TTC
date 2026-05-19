<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/media.php';

require_login();
if (!(can('quiz.manage') || can('exams.manage'))) {
  json_out(['ok' => false, 'error' => 'forbidden'], 403);
}

$q = trim((string)($_GET['q'] ?? ''));
$ym = trim((string)($_GET['ym'] ?? ''));
$sort = trim((string)($_GET['sort'] ?? 'newest'));

$items = media_list_files(1000);

foreach ($items as &$item) {
  $path = str_replace('\\', '/', (string)($item['path'] ?? ''));

  if ($path !== '') {
    if (preg_match('~(/uploads/.*)$~i', $path, $m)) {
      $path = $m[1];
    } elseif (stripos($path, 'uploads/') === 0) {
      $path = '/' . $path;
    }
  }

  $item['path'] = $path;
}
unset($item);

if ($q !== '') {
  $items = array_values(array_filter($items, function(array $item) use ($q) {
    return stripos((string)($item['name'] ?? ''), $q) !== false
      || stripos((string)($item['path'] ?? ''), $q) !== false
      || stripos((string)($item['ym'] ?? ''), $q) !== false
      || stripos((string)($item['title'] ?? ''), $q) !== false
      || stripos((string)($item['alt'] ?? ''), $q) !== false
      || stripos((string)($item['caption'] ?? ''), $q) !== false;
  }));
}

if ($ym !== '') {
  $items = array_values(array_filter($items, fn(array $item) => (string)($item['ym'] ?? '') === $ym));
}

if ($sort === 'oldest') {
  usort($items, fn($a, $b) => ((int)($a['mtime'] ?? 0) <=> (int)($b['mtime'] ?? 0)));
} elseif ($sort === 'name_asc') {
  usort($items, fn($a, $b) => strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
} elseif ($sort === 'name_desc') {
  usort($items, fn($a, $b) => strcasecmp((string)($b['name'] ?? ''), (string)($a['name'] ?? '')));
}

$folders = [];
foreach ($items as $item) {
  $key = (string)($item['ym'] ?? '');
  if ($key !== '') $folders[$key] = true;
}
$folders = array_keys($folders);
rsort($folders, SORT_NATURAL);

json_out(['ok' => true, 'items' => array_slice($items, 0, 400), 'folders' => $folders], 200);