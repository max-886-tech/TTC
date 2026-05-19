<?php
declare(strict_types=1);

namespace App\Http;

/**
 * Tiny request helper (framework-less).
 * Keeps controllers clean & consistent.
 */
final class Request {
  public static function method(): string {
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
  }

  public static function isPost(): bool {
    return self::method() === 'POST';
  }

  public static function getString(string $key, string $default = ''): string {
    $v = $_GET[$key] ?? $default;
    if (is_array($v)) return $default;
    return trim((string)$v);
  }

  public static function getInt(string $key, int $default = 0): int {
    $v = $_GET[$key] ?? $default;
    if (is_array($v)) return $default;
    return (int)$v;
  }

  public static function postString(string $key, string $default = ''): string {
    $v = $_POST[$key] ?? $default;
    if (is_array($v)) return $default;
    return trim((string)$v);
  }

  public static function postInt(string $key, int $default = 0): int {
    $v = $_POST[$key] ?? $default;
    if (is_array($v)) return $default;
    return (int)$v;
  }

  public static function postBool(string $key): bool {
    return isset($_POST[$key]);
  }
}
