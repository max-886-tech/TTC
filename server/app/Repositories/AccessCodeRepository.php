<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class AccessCodeRepository {
  public function __construct(private PDO $pdo) {}

  public function findById(int $id): ?array {
    $st = $this->pdo->prepare("SELECT * FROM access_codes WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public function create(array $data): int {
    // Build insert dynamically (keeps backward compatibility with older schemas)
    $cols = [];
    $qs   = [];
    $vals = [];

    foreach ($data as $col => $val) {
      $isOptional = in_array($col, ['user_id','user_name','user_email','user_phone','user_address','r2_key','r2_url','quiz_exam_id','mock_exam_id','resource_type','max_users','max_uses','uses_count','mode_access_json'], true);
      if ($isOptional && function_exists('db_has_column') && !db_has_column($this->pdo, 'access_codes', $col)) {
        continue;
      }
      $cols[] = $col;
      $qs[] = '?';
      $vals[] = $val;
    }

    $sql = "INSERT INTO access_codes (" . implode(',', $cols) . ") VALUES (" . implode(',', $qs) . ")";
    $st = $this->pdo->prepare($sql);
    $st->execute($vals);
    return (int)$this->pdo->lastInsertId();
  }

  public function update(int $id, array $data): void {
    $sets = [];
    $vals = [];

    foreach ($data as $col => $val) {
      $isOptional = in_array($col, ['user_id','user_name','user_email','user_phone','user_address','r2_key','r2_url','quiz_exam_id','mock_exam_id','resource_type','max_users','max_uses','uses_count','mode_access_json'], true);
      if ($isOptional && function_exists('db_has_column') && !db_has_column($this->pdo, 'access_codes', $col)) {
        continue;
      }
      $sets[] = "$col = ?";
      $vals[] = $val;
    }

    if (!$sets) return;

    $vals[] = $id;
    $sql = "UPDATE access_codes SET " . implode(', ', $sets) . " WHERE id = ? LIMIT 1";
    $st = $this->pdo->prepare($sql);
    $st->execute($vals);
  }
}
