<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\QuestionRepository;

final class QuestionService {
  public function __construct(private QuestionRepository $repo) {}

  public function countByExamId(string $examId): int {
    return $this->repo->countByExamId($examId);
  }

  /** @return array<int,array<string,mixed>> */
  public function findByExamId(string $examId, int $limit = 500, int $offset = 0): array {
    return $this->repo->findByExamId($examId, $limit, $offset);
  }

  /** @return array<string,mixed> */
  public function listForAdmin(string $examId, int $page = 1, int $perPage = 25, string $search = '', string $questionType = '', string $status = 'all', string $sort = 'oldest'): array {
    $allowedPerPage = [25, 50, 75, 100, 150, 200];
    $allowedTypes = ['', 'single', 'multiple', 'yes_no', 'dropdown_matrix', 'drag_drop'];
    $allowedStatuses = ['all', 'active', 'inactive'];
    $allowedSort = ['oldest', 'newest'];

    if (!in_array($perPage, $allowedPerPage, true)) $perPage = 25;
    if (!in_array($questionType, $allowedTypes, true)) $questionType = '';
    if (!in_array($status, $allowedStatuses, true)) $status = 'all';
    if (!in_array($sort, $allowedSort, true)) $sort = 'oldest';
    $page = max(1, $page);
    $search = trim($search);


    $totalQuestions = $this->repo->countFilteredByExamId($examId, $search, $questionType, $status, $sort);
    $totalPages = max(1, (int)ceil($totalQuestions / $perPage));
    if ($page > $totalPages) $page = $totalPages;

    $offset = ($page - 1) * $perPage;
    $questions = $this->repo->findFilteredByExamId($examId, $search, $questionType, $status, $sort, $perPage, $offset);
    $activeQuestions = $this->repo->countActiveByExamId($examId);

    return [
      'rows' => $questions,
      'total' => $totalQuestions,
      'active' => $activeQuestions,
      'page' => $page,
      'perPage' => $perPage,
      'totalPages' => $totalPages,
      'start' => $totalQuestions > 0 ? ($offset + 1) : 0,
      'end' => min($offset + $perPage, $totalQuestions),
      'allowedPerPage' => $allowedPerPage,
      'search' => $search,
      'questionType' => $questionType,
      'status' => $status,
      'sort' => $sort,
      'allowedTypes' => $allowedTypes,
      'allowedStatuses' => $allowedStatuses,
      'allowedSort' => $allowedSort,
    ];
  }

  public function bulkSetActiveStatus(string $examId, array $ids, bool $isActive): int {
    return $this->repo->bulkSetActiveStatus($examId, $ids, $isActive);
  }

  public function bulkDeleteByExamId(string $examId, array $ids): int {
    return $this->repo->bulkDeleteByExamId($examId, $ids);
  }
}
