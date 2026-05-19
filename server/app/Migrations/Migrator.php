<?php
declare(strict_types=1);

namespace App\Migrations;

use PDO;
use Throwable;

/**
 * Simple filesystem-based migrator (Phase 4)
 * - Stores applied versions in schema_migrations
 * - Executes /migrations/*.php in filename order
 *
 * Each migration file must `return function(PDO $pdo): void { ... }`.
 */
final class Migrator {
  public static function run(PDO $pdo, string $migrationsDir): void {
    self::ensureMigrationsTable($pdo);

    $applied = self::getApplied($pdo);
    $files = glob(rtrim($migrationsDir, '/\\') . '/*.php') ?: [];
    sort($files, SORT_STRING);

    foreach ($files as $file) {
      $version = basename($file, '.php');
      if (isset($applied[$version])) continue;

      $callable = require $file;
      if (!is_callable($callable)) {
        throw new \RuntimeException("Migration must return callable: $file");
      }

      // Run single migration in a transaction when possible.
      try {
        if (!$pdo->inTransaction()) $pdo->beginTransaction();
        $callable($pdo);
        self::markApplied($pdo, $version);
        if ($pdo->inTransaction()) $pdo->commit();
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
      }
    }
  }

  private static function ensureMigrationsTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
      version VARCHAR(64) NOT NULL,
      applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
  }

  /** @return array<string, true> */
  private static function getApplied(PDO $pdo): array {
    $rows = $pdo->query("SELECT version FROM schema_migrations")->fetchAll() ?: [];
    $out = [];
    foreach ($rows as $r) {
      $out[(string)$r['version']] = true;
    }
    return $out;
  }

  private static function markApplied(PDO $pdo, string $version): void {
    $st = $pdo->prepare("INSERT INTO schema_migrations (version, applied_at) VALUES (?, NOW())");
    $st->execute([$version]);
  }
}
