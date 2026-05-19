<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\ExamRepository;

final class ExamService {
  public function __construct(private ExamRepository $repo) {}

  /** @return array<int,array<string,mixed>> */
  public function search(string $q, int $limit = 25): array {
    return $this->repo->search($q, $limit);
  }

  /** @return array<int,array<string,mixed>> */
  public function listQuizExams(): array {
    return $this->repo->listQuizExams();
  }

  /** @return array<string,mixed> */
  public function listForAdmin(): array {
    $exams = $this->repo->listAllForAdmin();
    $counts = $this->repo->questionCounts();
    $quizCount = 0;
    $enblCount = 0;
    $dumps = [];
    foreach ($exams as $tmpExam) {
      if ((string)($tmpExam['resource_type'] ?? '') === 'quiz') {
        $quizCount++;
        continue;
      }
      $enblCount++;
      $dumps[] = $tmpExam;
    }
    return [
      'rows' => $dumps,
      'questionCounts' => $counts,
      'total' => count($dumps),
      'quizCount' => 0,
      'enblCount' => $enblCount,
    ];
  }

  public function findQuizExam(string $examId): ?array {
    $exam = $this->repo->findByExamId($examId);
    if (!$exam || (string)($exam['resource_type'] ?? '') !== 'quiz') return null;
    return $exam;
  }

  public function deleteExam(int $id): array {
    return $this->repo->deleteExamWithRelations($id);
  }
}
