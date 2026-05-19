<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class MediaRepository {
  public function __construct(private PDO $pdo) {}

  public function findById(int $id): ?array {
    if (!db_has_table($this->pdo, 'media')) return null;
    $st = $this->pdo->prepare('SELECT * FROM media WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  /** @return array<int,array<string,mixed>> */
  public function recent(int $limit = 50): array {
    if (!db_has_table($this->pdo, 'media')) return [];
    $limit = max(1, min(200, $limit));
    $st = $this->pdo->query("SELECT * FROM media ORDER BY id DESC LIMIT {$limit}");
    return $st ? ($st->fetchAll() ?: []) : [];
  }
}
