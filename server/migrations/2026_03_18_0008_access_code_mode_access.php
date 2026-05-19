<?php
declare(strict_types=1);

return function(PDO $pdo): void {
  $hasTable = function(string $table) use ($pdo): bool {
    $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  };

  $hasCol = function(string $table, string $col) use ($pdo): bool {
    $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
  };

  if ($hasTable('access_codes') && !$hasCol('access_codes', 'mode_access_json')) {
    $pdo->exec("ALTER TABLE access_codes ADD COLUMN mode_access_json LONGTEXT NULL");
  }
};
