<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class QuestionRepository {
  public function __construct(private PDO $pdo) {}

  public function countByExamId(string $examId): int {
    return $this->countFilteredByExamId($examId);
  }

  /** @return array<int,array<string,mixed>> */
  public function findByExamId(string $examId, int $limit = 500, int $offset = 0): array {
    return $this->findFilteredByExamId($examId, '', '', 'all', 'oldest', $limit, $offset);
  }

  public function countActiveByExamId(string $examId): int {
    return $this->countFilteredByExamId($examId, '', '', 'active', 'oldest');
  }

  public function countFilteredByExamId(string $examId, string $search = '', string $questionType = '', string $status = 'all', string $sort = 'oldest'): int {
    if (!db_has_table($this->pdo, 'questions') && !db_has_table($this->pdo, 'quiz_questions')) return 0;
    $table = db_has_table($this->pdo, 'quiz_questions') ? 'quiz_questions' : 'questions';

    [$whereSql, $params] = $this->buildFilterSql($examId, $search, $questionType, $status);
    $st = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} {$whereSql}");
    $st->execute($params);
    return (int)$st->fetchColumn();
  }

  /** @return array<int,array<string,mixed>> */
  public function findFilteredByExamId(string $examId, string $search = '', string $questionType = '', string $status = 'all', string $sort = 'oldest', int $limit = 500, int $offset = 0): array {
    if (!db_has_table($this->pdo, 'questions') && !db_has_table($this->pdo, 'quiz_questions')) return [];
    $table = db_has_table($this->pdo, 'quiz_questions') ? 'quiz_questions' : 'questions';
    $limit = max(1, min(2000, $limit));
    $offset = max(0, $offset);

    [$whereSql, $params] = $this->buildFilterSql($examId, $search, $questionType, $status);
    $orderBy = $this->buildOrderBy($sort);
    $st = $this->pdo->prepare("SELECT * FROM {$table} {$whereSql} {$orderBy} LIMIT {$limit} OFFSET {$offset}");
    $st->execute($params);
    return $st->fetchAll() ?: [];
  }

  public function bulkSetActiveStatus(string $examId, array $ids, bool $isActive): int {
    if (!db_has_table($this->pdo, 'questions') && !db_has_table($this->pdo, 'quiz_questions')) return 0;
    $table = db_has_table($this->pdo, 'quiz_questions') ? 'quiz_questions' : 'questions';
    $ids = $this->sanitizeIds($ids);
    if ($ids === []) return 0;

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([(int)($isActive ? 1 : 0), $examId], $ids);
    $st = $this->pdo->prepare("UPDATE {$table} SET is_active = ? WHERE exam_id = ? AND id IN ({$placeholders})");
    $st->execute($params);
    return $st->rowCount();
  }

  public function bulkDeleteByExamId(string $examId, array $ids): int {
    if (!db_has_table($this->pdo, 'questions') && !db_has_table($this->pdo, 'quiz_questions')) return 0;
    $table = db_has_table($this->pdo, 'quiz_questions') ? 'quiz_questions' : 'questions';
    $ids = $this->sanitizeIds($ids);
    if ($ids === []) return 0;

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$examId], $ids);
    $st = $this->pdo->prepare("DELETE FROM {$table} WHERE exam_id = ? AND id IN ({$placeholders})");
    $st->execute($params);
    return $st->rowCount();
  }

  /** @return array{0:string,1:array<int,mixed>} */
  private function buildFilterSql(string $examId, string $search, string $questionType, string $status = 'all'): array {
    $where = ['exam_id = ?'];
    $params = [$examId];

    $search = trim($search);
    if ($search !== '') {
      $where[] = 'question_text LIKE ?';
      $params[] = '%' . $search . '%';
    }


    $questionType = trim($questionType);
    if ($questionType !== '') {
      $where[] = 'question_type = ?';
      $params[] = $questionType;
    }

    $status = strtolower(trim($status));
    if ($status === 'active') {
      $where[] = 'is_active = 1';
    } elseif ($status === 'inactive') {
      $where[] = 'is_active = 0';
    }

    return ['WHERE ' . implode(' AND ', $where), $params];
  }

  private function buildOrderBy(string $sort): string {
    $sort = strtolower(trim($sort));
    if ($sort === 'newest') {
      return 'ORDER BY created_at DESC, sort_order DESC, id DESC';
    }

    return 'ORDER BY sort_order ASC, id ASC';
  }

  /** @return array<int,int> */
  private function sanitizeIds(array $ids): array {
    $out = [];
    foreach ($ids as $id) {
      $value = (int)$id;
      if ($value > 0) $out[] = $value;
    }
    return array_values(array_unique($out));
  }
}
