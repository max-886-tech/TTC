<?php
// Lightweight environment loader for .env style files.
if (!function_exists('enbl_env_load')) {
  function enbl_env_load(string $baseDir): void {
    static $loaded = [];
    $baseDir = rtrim($baseDir, '/');
    if (isset($loaded[$baseDir])) return;
    $loaded[$baseDir] = true;

    $candidates = [
      $baseDir . '/.env',
      dirname($baseDir) . '/.env',
    ];

    foreach ($candidates as $file) {
      if (!is_file($file) || !is_readable($file)) continue;
      $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      if (!$lines) continue;
      foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($value !== '' && ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'")))) {
          $value = substr($value, 1, -1);
        }
        if ($key === '') continue;
        if (getenv($key) === false) putenv($key . '=' . $value);
        if (!isset($_ENV[$key])) $_ENV[$key] = $value;
        if (!isset($_SERVER[$key])) $_SERVER[$key] = $value;
      }
      break;
    }
  }
}

if (!function_exists('enbl_env')) {
  function enbl_env(string $key, $default = null) {
    $value = getenv($key);
    if ($value === false) {
      $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
    }
    if ($value === null || $value === false || $value === '') return $default;
    $lower = strtolower((string)$value);
    if (in_array($lower, ['true', '(true)', 'on', 'yes'], true)) return true;
    if (in_array($lower, ['false', '(false)', 'off', 'no'], true)) return false;
    if (in_array($lower, ['null', '(null)'], true)) return null;
    if (is_numeric($value) && preg_match('/^-?\d+$/', (string)$value)) return (int)$value;
    return $value;
  }
}
