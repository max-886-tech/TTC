<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Admin\AdminPage;
use App\Repositories\ExamRepository;
use App\Repositories\QuestionRepository;
use App\Services\ExamService;
use App\Services\QuestionService;

final class QuestionsController extends BaseAdminController {
  public static function handle(): void {
    (new self())->run();
  }

  private function run(): void {
    $this->boot('quiz.manage');

    $examId = trim((string)$this->query('exam_id', $this->post('exam_id', '')));
    if ($examId === '') $this->redirect('/admin/exams.php');

    $examService = new ExamService(new ExamRepository($this->pdo));
    $questionService = new QuestionService(new QuestionRepository($this->pdo));

    $exam = $examService->findQuizExam($examId);
    if (!$exam) $this->redirect('/admin/exams.php');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      csrf_verify();
      $action = trim((string)$this->post('bulk_action', ''));
      $ids = $this->post('selected_ids', []);
      if (!is_array($ids)) $ids = [];

      if ($ids === []) {
        $this->flash('warning', 'Please select at least one question.');
      } elseif ($action === 'activate') {
        $count = $questionService->bulkSetActiveStatus($examId, $ids, true);
        $this->flash('success', $count . ' question(s) activated.');
      } elseif ($action === 'deactivate') {
        $count = $questionService->bulkSetActiveStatus($examId, $ids, false);
        $this->flash('success', $count . ' question(s) deactivated.');
      } elseif ($action === 'delete') {
        $count = $questionService->bulkDeleteByExamId($examId, $ids);
        $this->flash('success', $count . ' question(s) deleted.');
      } else {
        $this->flash('warning', 'Please choose a valid bulk action.');
      }

      $redirectParams = [
        'exam_id' => $examId,
        'page' => max(1, (int)$this->post('page', 1)),
        'per_page' => (int)$this->post('per_page', 25),
        'search' => trim((string)$this->post('search', '')),
        'question_type' => trim((string)$this->post('question_type', '')),
        'status' => trim((string)$this->post('status', 'all')),
        'sort' => trim((string)$this->post('sort', 'oldest')),
      ];
      $this->redirect('/admin/questions.php?' . http_build_query($redirectParams));
    }

    $page = max(1, (int)$this->query('page', 1));
    $perPage = (int)$this->query('per_page', 25);
    $search = trim((string)$this->query('search', ''));
    $questionType = trim((string)$this->query('question_type', ''));
    $status = trim((string)$this->query('status', 'all'));
    $sort = trim((string)$this->query('sort', 'oldest'));

    $result = $questionService->listForAdmin($examId, $page, $perPage, $search, $questionType, $status, $sort);

    view('admin/questions', [
      'examId' => $examId,
      'exam' => $exam,
      'rows' => $result['rows'],
      'total' => $result['total'],
      'active' => $result['active'],
      'page' => $result['page'],
      'perPage' => $result['perPage'],
      'totalPages' => $result['totalPages'],
      'start' => $result['start'],
      'end' => $result['end'],
      'allowedPerPage' => $result['allowedPerPage'],
      'search' => $result['search'],
      'questionType' => $result['questionType'],
      'status' => $result['status'],
      'sort' => $result['sort'],
      'allowedTypes' => $result['allowedTypes'],
      'allowedStatuses' => $result['allowedStatuses'],
      'messageSuccess' => AdminPage::pullFlash('success'),
      'messageWarning' => AdminPage::pullFlash('warning'),
    ]);

    AdminPage::finish();
  }
}
