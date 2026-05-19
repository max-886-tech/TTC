<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;
use Throwable;

final class ExamRepository {
  public function __construct(private PDO $pdo) {}

  /** @return array<int,array<string,mixed>> */
  public function search(string $q, int $limit = 25): array {
    if (function_exists('quiz_ensure_schema')) {
      if (!$this->pdo->inTransaction()) quiz_ensure_schema($this->pdo);
    }

    $q = trim($q);
    if ($q === '') return [];

    $like = '%' . $q . '%';
    $sql = "SELECT id, exam_id, title, r2_key, r2_url
            FROM exams
            WHERE exam_id LIKE ? OR title LIKE ?
            ORDER BY created_at DESC, id DESC
            LIMIT " . max(1, min(100, $limit));

    $st = $this->pdo->prepare($sql);
    $st->execute([$like, $like]);
    return $st->fetchAll() ?: [];
  }

  /** @return array<int,array<string,mixed>> */
  public function listQuizExams(): array {
    if (!db_has_table($this->pdo, 'exams')) return [];
    $sql = "SELECT exam_id, exam_name FROM exams WHERE resource_type='quiz' ORDER BY exam_name, exam_id";
    $st = $this->pdo->query($sql);
    return $st ? ($st->fetchAll() ?: []) : [];
  }

  /** @return array<int,array<string,mixed>> */
  public function listAllForAdmin(): array {
    if (!db_has_table($this->pdo, 'exams')) return [];
    $st = $this->pdo->query("SELECT * FROM exams ORDER BY id DESC");
    return $st ? ($st->fetchAll() ?: []) : [];
  }

  public function findById(int $id): ?array {
    if ($id <= 0 || !db_has_table($this->pdo, 'exams')) return null;
    $st = $this->pdo->prepare("SELECT * FROM exams WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  public function findByExamId(string $examId): ?array {
    $examId = trim($examId);
    if ($examId === '' || !db_has_table($this->pdo, 'exams')) return null;
    $st = $this->pdo->prepare("SELECT * FROM exams WHERE exam_id = ? LIMIT 1");
    $st->execute([$examId]);
    $row = $st->fetch();
    return $row ?: null;
  }

  /** @return array<string,int> */
  public function questionCounts(): array {
    $counts = [];
    if (!db_has_table($this->pdo, 'quiz_questions')) return $counts;
    try {
      $stmt = $this->pdo->query("SELECT exam_id, COUNT(*) AS c FROM quiz_questions GROUP BY exam_id");
      if ($stmt) {
        foreach ($stmt->fetchAll() ?: [] as $r) {
          $counts[(string)($r['exam_id'] ?? '')] = (int)($r['c'] ?? 0);
        }
      }
    } catch (Throwable) {
    }
    return $counts;
  }

  public function deleteExamWithRelations(int $id): array {
    $exam = $this->findById($id);
    if (!$exam) return ['ok' => false, 'reason' => 'not_found'];

    $examCode = (string)($exam['exam_id'] ?? '');
    $examType = (string)($exam['resource_type'] ?? '');

    try {
      $this->pdo->beginTransaction();
      if ($examType === 'quiz') {
        $delAttempts = $this->pdo->prepare("DELETE FROM quiz_attempts WHERE exam_id = ?");
        $delAttempts->execute([$examCode]);

        $delQuestions = $this->pdo->prepare("DELETE FROM quiz_questions WHERE exam_id = ?");
        $delQuestions->execute([$examCode]);
      }

      $delExam = $this->pdo->prepare("DELETE FROM exams WHERE id = ? LIMIT 1");
      $delExam->execute([$id]);
      $this->pdo->commit();
      return ['ok' => true, 'exam' => $exam];
    } catch (Throwable $e) {
      if ($this->pdo->inTransaction()) $this->pdo->rollBack();
      return ['ok' => false, 'reason' => 'exception', 'error' => $e->getMessage(), 'exam' => $exam];
    }
  }
}
