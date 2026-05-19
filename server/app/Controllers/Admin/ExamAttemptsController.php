<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Admin\AdminPage;
use App\Repositories\AttemptRepository;
use App\Repositories\ExamRepository;
use App\Services\AttemptService;
use App\Services\ExamService;
use App\Services\ReportingService;

final class ExamAttemptsController extends BaseAdminController {
  public static function handle(): void {
    (new self())->run();
  }

  private function run(): void {
    $this->boot('attempts.view');

    $attempts = new AttemptService(new AttemptRepository($this->pdo));
    $exams = new ExamService(new ExamRepository($this->pdo));

    $result = $attempts->paginateForAdmin($_GET);
    $filters = $result['filters'];
    $rows = $result['rows'];
    $totalRows = $result['totalRows'];
    $totalPages = $result['totalPages'];
    $page = $result['page'];
    $perPage = $result['perPage'];
    $offset = $result['offset'];
    $allowedPerPage = $result['meta']['allowedPerPage'];

    $examsList = $exams->listQuizExams();
    $modeOptions = ['exam' => 'Exam', 'practice' => 'Practice', 'demo' => 'Demo'];
    $rangeOptions = [
      'today' => 'Today',
      'yesterday' => 'Yesterday',
      'last7' => 'Last 7 days',
      'last15' => 'Last 15 days',
      'last30' => 'Last 30 days',
      'this_month' => 'This month',
      'past_month' => 'Past month',
      'last3months' => 'Last 3 months',
      'this_year' => 'This year',
      'all' => 'All time',
      'custom' => 'Custom range',
    ];
    $startRow = $totalRows > 0 ? ($offset + 1) : 0;
    $endRow = min($offset + $perPage, $totalRows);
    $reviewBaseUrl = defined('QUIZ_REVIEW_BASE_URL') ? rtrim(QUIZ_REVIEW_BASE_URL, '/') : 'https://thetruecerts.com';
    $displayRows = array_map(static fn(array $row): array => ReportingService::normalizeAttemptRow($row, $reviewBaseUrl), $rows);
    $buildQuery = static fn(array $overrides = []): string => AdminPage::queryString($_GET, $overrides);

    view('admin/exam_attempts', [
      'rows' => $displayRows,
      'totalRows' => $totalRows,
      'totalPages' => $totalPages,
      'page' => $page,
      'perPage' => $perPage,
      'allowedPerPage' => $allowedPerPage,
      'exams' => $examsList,
      'modeOptions' => $modeOptions,
      'rangeOptions' => $rangeOptions,
      'startRow' => $startRow,
      'endRow' => $endRow,
      'reviewBaseUrl' => $reviewBaseUrl,
      'buildQuery' => $buildQuery,
      'q' => $filters['q'],
      'examFilter' => $filters['exam_id'],
      'statusFilter' => $filters['status'],
      'modeFilter' => $filters['mode'],
      'datePreset' => $filters['range'],
      'fromDate' => $filters['from'],
      'toDate' => $filters['to'],
    ]);

    AdminPage::finish();
  }
}
