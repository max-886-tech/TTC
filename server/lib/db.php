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
