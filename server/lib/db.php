<?php
require_once __DIR__ . '/../config.php';

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;

  $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);

  return $pdo;
}

/**
 * Ensure access_codes table supports both:
 * - max_users: how many unique users/devices can activate the code
 * - max_uses:  how many total successful uses are allowed (0 = unlimited)
 * - uses_count: how many times the code has been used successfully
 *
 * Backward compatible migration:
 * Older installs used `max_uses` to mean max activations; we rename it to `max_users`
 * and then add the new `max_uses` + `uses_count` columns.
 */
// NOTE (Phase 4): schema changes are now handled by migrations (see /migrations and App\Migrations\Migrator).
// Keep db_has_* helpers below for migrations + optional checks.

function code_hash(string $code): string {
  $code = trim($code);
  return hash('sha256', CODE_PEPPER . $code);
}

function device_hash(string $device_id): string {
  $device_id = trim($device_id);
  return hash('sha256', DEVICE_PEPPER . $device_id);
}

function utc_now_sql(): string {
  return (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
}

/**
 * Check whether a column exists on the current database.
 * Safe for optional/rolling schema updates.
 */
function db_has_column(PDO $pdo, string $table, string $column): bool {
  $sql = "
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
    LIMIT 1
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$table, $column]);
  return (bool)$stmt->fetchColumn();
}

/**
 * Check whether a table exists on the current database.
 */
function db_has_table(PDO $pdo, string $table): bool {
  $sql = "
    SELECT 1
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
    LIMIT 1
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$table]);
  return (bool)$stmt->fetchColumn();
}
