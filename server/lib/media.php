<?php
require_once __DIR__ . '/helpers.php';

function media_root_abs(): string {
  return dirname(__DIR__) . '/uploads';
}

function media_root_web(): string {
  return '/uploads';
}

function media_write_htaccess_if_needed(string $dir): void {
  $file = rtrim($dir, '/\\') . '/.htaccess';
  if (!is_file($file)) {
    @file_put_contents($file, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|sh)$\">\nDeny from all\n</FilesMatch>\n");
  }
}

function media_ensure_root(): void {
  $root = media_root_abs();
  if (!is_dir($root) && !mkdir($root, 0755, true)) {
    throw new RuntimeException('Unable to create uploads root.');
  }
  media_write_htaccess_if_needed($root);
}

function media_year_month_parts(?DateTimeInterface $dt = null): array {
  $dt = $dt ?: new DateTime('now');
  return [$dt->format('Y'), $dt->format('m')];
}

function media_relative_from_parts(string $year, string $month): string {
  return '/uploads/' . $year . '/' . $month;
}

function media_absolute_from_relative(string $relative): string {
  return dirname(__DIR__) . '/' . ltrim($relative, '/');
}

function media_is_valid_relative(string $relative): bool {
  if ($relative === '' || $relative[0] !== '/') return false;
  if (strpos($relative, '..') !== false) return false;
  return (bool)preg_match('#^/uploads/[0-9]{4}/[0-9]{2}/[A-Za-z0-9._-]+$#', $relative);
}

function media_meta_path_from_relative(string $relative): string {
  return media_absolute_from_relative($relative) . '.meta.json';
}

function media_filename_without_ext(string $name): string {
  return pathinfo($name, PATHINFO_FILENAME);
}

function media_guess_title_from_name(string $name): string {
  $base = media_filename_without_ext($name);
  $base = preg_replace('/[_-]+/', ' ', $base);
  $base = preg_replace('/\s+/', ' ', (string)$base);
  return trim((string)$base);
}

function media_sanitize_basename(string $value): string {
  $value = trim($value);
  $value = preg_replace('/\.[A-Za-z0-9]+$/', '', $value);
  $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value);
  $value = preg_replace('/-+/', '-', (string)$value);
  $value = trim((string)$value, '-._ ');
  return $value !== '' ? $value : 'media-file';
}

function media_detect_image_meta(string $absPath): array {
  $out = ['width' => null, 'height' => null, 'mime' => null];
  if (!is_file($absPath)) return $out;
  $info = @getimagesize($absPath);
  if (!$info) return $out;
  return [
    'width' => isset($info[0]) ? (int)$info[0] : null,
    'height' => isset($info[1]) ? (int)$info[1] : null,
    'mime' => isset($info['mime']) ? (string)$info['mime'] : null,
  ];
}

function media_read_meta(string $relative): array {
  if (!media_is_valid_relative($relative)) {
    throw new RuntimeException('Invalid media path.');
  }
  $abs = media_absolute_from_relative($relative);
  $name = basename($relative);
  $img = media_detect_image_meta($abs);
  $base = [
    'path' => $relative,
    'name' => $name,
    'basename' => media_filename_without_ext($name),
    'filename' => $name,
    'title' => media_guess_title_from_name($name),
    'alt' => '',
    'caption' => '',
    'description' => '',
    'width' => $img['width'],
    'height' => $img['height'],
    'mime' => $img['mime'],
    'size' => is_file($abs) ? (int)filesize($abs) : 0,
    'mtime' => is_file($abs) ? (int)filemtime($abs) : 0,
    'ym' => preg_match('#^/uploads/([0-9]{4})/([0-9]{2})/#', $relative, $m) ? ($m[1] . '/' . $m[2]) : '',
    'url' => $relative,
  ];

  $metaPath = media_meta_path_from_relative($relative);
  if (!is_file($metaPath)) return $base;
  $json = @file_get_contents($metaPath);
  $data = json_decode((string)$json, true);
  if (!is_array($data)) return $base;
  return array_merge($base, array_intersect_key($data, array_flip([
    'title','alt','caption','description','original_name','uploaded_at','updated_at','width','height','mime'
  ])));
}

function media_write_meta(string $relative, array $meta): array {
  if (!media_is_valid_relative($relative)) {
    throw new RuntimeException('Invalid media path.');
  }
  $abs = media_absolute_from_relative($relative);
  if (!is_file($abs)) {
    throw new RuntimeException('Media file not found.');
  }
  $current = media_read_meta($relative);
  $img = media_detect_image_meta($abs);
  $merged = array_merge($current, [
    'title' => trim((string)($meta['title'] ?? $current['title'] ?? '')),
    'alt' => trim((string)($meta['alt'] ?? $current['alt'] ?? '')),
    'caption' => trim((string)($meta['caption'] ?? $current['caption'] ?? '')),
    'description' => trim((string)($meta['description'] ?? $current['description'] ?? '')),
    'original_name' => (string)($current['original_name'] ?? basename($relative)),
    'uploaded_at' => (string)($current['uploaded_at'] ?? date('c', is_file($abs) ? filemtime($abs) : time())),
    'updated_at' => date('c'),
    'width' => $img['width'],
    'height' => $img['height'],
    'mime' => $img['mime'],
  ]);
  $metaPath = media_meta_path_from_relative($relative);
  $json = json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($json === false || @file_put_contents($metaPath, $json) === false) {
    throw new RuntimeException('Unable to save media metadata.');
  }
  return media_read_meta($relative);
}

function media_save_uploaded_image(array $file, string $prefix = 'img', int $maxBytes = 5242880): string {
  media_ensure_root();

  $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
  if ($err !== UPLOAD_ERR_OK) {
    if ($err === UPLOAD_ERR_NO_FILE) throw new RuntimeException('No file selected.');
    throw new RuntimeException('Image upload failed (error code ' . $err . ').');
  }

  $tmp = (string)($file['tmp_name'] ?? '');
  $orig = (string)($file['name'] ?? '');
  $size = (int)($file['size'] ?? 0);
  if ($size <= 0 || $size > $maxBytes) {
    throw new RuntimeException('Image must be under 5MB.');
  }

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->file($tmp) ?: '';
  $mimeToExt = [
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
  ];
  if (!isset($mimeToExt[$mime])) {
    throw new RuntimeException('Only PNG, JPG, WEBP, or GIF images are allowed.');
  }

  $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
  if ($ext === 'jpeg') $ext = 'jpg';
  if ($ext === '' || !in_array($ext, ['png','jpg','jpeg','webp','gif'], true)) {
    $ext = $mimeToExt[$mime];
  }
  if ($ext === 'jpeg') $ext = 'jpg';

  [$year, $month] = media_year_month_parts();
  $relDir = media_relative_from_parts($year, $month);
  $absDir = media_absolute_from_relative($relDir);
  if (!is_dir($absDir) && !mkdir($absDir, 0755, true)) {
    throw new RuntimeException('Unable to create upload folder.');
  }
  media_write_htaccess_if_needed($absDir);

  $safePrefix = preg_replace('/[^A-Za-z0-9_-]+/', '_', $prefix) ?: 'img';
  $fileName = $safePrefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
  $destAbs = $absDir . '/' . $fileName;
  if (!move_uploaded_file($tmp, $destAbs)) {
    throw new RuntimeException('Failed to save uploaded image.');
  }
  $relative = $relDir . '/' . $fileName;
  media_write_meta($relative, [
    'title' => media_guess_title_from_name($orig !== '' ? $orig : $fileName),
    'original_name' => $orig !== '' ? $orig : $fileName,
    'alt' => '',
    'caption' => '',
    'description' => '',
  ]);
  return $relative;
}

function media_list_files(int $limit = 300): array {
  media_ensure_root();
  $root = media_root_abs();
  $items = [];
  if (!is_dir($root)) return [];

  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );

  foreach ($it as $file) {
    if (!$file->isFile()) continue;
    $ext = strtolower($file->getExtension());
    if (!in_array($ext, ['png','jpg','jpeg','webp','gif'], true)) continue;
    $abs = str_replace('\\', '/', $file->getPathname());
    $rootNorm = str_replace('\\', '/', dirname(__DIR__));
    $rel = substr($abs, strlen($rootNorm));
    if (!media_is_valid_relative($rel)) continue;
    $items[] = media_read_meta($rel);
  }

  usort($items, fn($a, $b) => ((int)($b['mtime'] ?? 0) <=> (int)($a['mtime'] ?? 0)));
  return array_slice($items, 0, $limit);
}

function media_delete_file(string $relative): bool {
  if (!media_is_valid_relative($relative)) {
    throw new RuntimeException('Invalid media path.');
  }
  $abs = media_absolute_from_relative($relative);
  if (!is_file($abs)) return false;
  $ok = @unlink($abs);
  $metaPath = media_meta_path_from_relative($relative);
  if (is_file($metaPath)) @unlink($metaPath);
  return $ok;
}

function media_get_item(string $relative): array {
  return media_read_meta($relative);
}

function media_update_item(string $relative, array $fields): array {
  if (!media_is_valid_relative($relative)) {
    throw new RuntimeException('Invalid media path.');
  }
  $abs = media_absolute_from_relative($relative);
  if (!is_file($abs)) {
    throw new RuntimeException('Media file not found.');
  }

  $newRelative = $relative;
  $requestedBasename = isset($fields['basename']) ? media_sanitize_basename((string)$fields['basename']) : '';
  if ($requestedBasename !== '') {
    $dirRel = rtrim(dirname($relative), '/\\');
    $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    $candidateRel = ($dirRel === '' || $dirRel === '.') ? ('/' . $requestedBasename . '.' . $ext) : ($dirRel . '/' . $requestedBasename . '.' . $ext);
    if ($candidateRel !== $relative) {
      $candidateAbs = media_absolute_from_relative($candidateRel);
      if (is_file($candidateAbs)) {
        throw new RuntimeException('A file with this name already exists in the same folder.');
      }
      if (!@rename($abs, $candidateAbs)) {
        throw new RuntimeException('Unable to rename media file.');
      }
      $oldMeta = media_meta_path_from_relative($relative);
      $newMeta = media_meta_path_from_relative($candidateRel);
      if (is_file($oldMeta)) @rename($oldMeta, $newMeta);
      $newRelative = $candidateRel;
    }
  }

  return media_write_meta($newRelative, [
    'title' => $fields['title'] ?? null,
    'alt' => $fields['alt'] ?? null,
    'caption' => $fields['caption'] ?? null,
    'description' => $fields['description'] ?? null,
  ]);
}

function media_crop_image(string $relative, int $x, int $y, int $width, int $height): array {
  if (!function_exists('imagecreatetruecolor')) {
    throw new RuntimeException('Image crop requires GD to be enabled on the server.');
  }
  if (!media_is_valid_relative($relative)) {
    throw new RuntimeException('Invalid media path.');
  }
  $abs = media_absolute_from_relative($relative);
  if (!is_file($abs)) {
    throw new RuntimeException('Media file not found.');
  }

  $srcBytes = @file_get_contents($abs);
  if ($srcBytes === false) {
    throw new RuntimeException('Unable to read image for cropping.');
  }
  $src = @imagecreatefromstring($srcBytes);
  if (!$src) {
    throw new RuntimeException('Unsupported image format for cropping.');
  }

  $srcW = imagesx($src);
  $srcH = imagesy($src);
  $x = max(0, min($srcW - 1, $x));
  $y = max(0, min($srcH - 1, $y));
  $width = max(1, min($srcW - $x, $width));
  $height = max(1, min($srcH - $y, $height));

  $dst = imagecreatetruecolor($width, $height);
  $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
  if (in_array($ext, ['png', 'webp', 'gif'], true)) {
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $width, $height, $transparent);
  }
  if (!imagecopy($dst, $src, 0, 0, $x, $y, $width, $height)) {
    imagedestroy($src);
    imagedestroy($dst);
    throw new RuntimeException('Unable to crop image.');
  }

  $ok = false;
  if ($ext === 'png') $ok = imagepng($dst, $abs);
  elseif ($ext === 'gif') $ok = imagegif($dst, $abs);
  elseif ($ext === 'webp' && function_exists('imagewebp')) $ok = imagewebp($dst, $abs, 90);
  else $ok = imagejpeg($dst, $abs, 92);

  imagedestroy($src);
  imagedestroy($dst);

  if (!$ok) {
    throw new RuntimeException('Failed to save cropped image.');
  }

  return media_write_meta($relative, []);
}
