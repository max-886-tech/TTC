<?php
declare(strict_types=1);

namespace App\Repositories;

use DateTime;
use PDO;

final class AttemptRepository {
  public function __construct(private PDO $pdo) {}

  /** @return array{rows:array<int,array<string,mixed>>,totalRows:int,totalPages:int,page:int,perPage:int,offset:int,filters:array<string,mixed>,meta:array<string,mixed>} */
  public function paginateForAdmin(array $filters): array {
    $q = trim((string)($filters['q'] ?? ''));
    $examFilter = trim((string)($filters['exam_id'] ?? ''));
    $statusFilter = trim((string)($filters['status'] ?? ''));
    $modeFilter = trim((string)($filters['mode'] ?? ''));
    $datePreset = trim((string)($filters['range'] ?? 'last30'));
    $fromDate = trim((string)($filters['from'] ?? ''));
    $toDate = trim((string)($filters['to'] ?? ''));
    $perPage = (int)($filters['per_page'] ?? 25);
    $page = max(1, (int)($filters['page'] ?? 1));

    $allowedPerPage = [10, 25, 50, 100, 200];
    if (!in_array($perPage, $allowedPerPage, true)) $perPage = 25;
    $allowedRanges = ['today','yesterday','last7','last15','last30','this_month','past_month','last3months','this_year','all','custom'];
    if (!in_array($datePreset, $allowedRanges, true)) $datePreset = 'last30';
    if ($datePreset !== 'custom') {
      [$presetFrom, $presetTo] = self::rangeDates($datePreset);
      $fromDate = $presetFrom;
      $toDate = $presetTo;
    }

    $hasExamsTable = db_has_table($this->pdo, 'exams');
    $hasAttemptUserId = db_has_column($this->pdo, 'quiz_attempts', 'user_id');
    $hasAttemptPct = db_has_column($this->pdo, 'quiz_attempts', 'percentage');
    $hasAttemptStatus = db_has_column($this->pdo, 'quiz_attempts', 'result_status');
    $hasAttemptMode = db_has_column($this->pdo, 'quiz_attempts', 'exam_mode');
    $hasAttemptIp = db_has_column($this->pdo, 'quiz_attempts', 'ip_address');
    $hasAttemptCountry = db_has_column($this->pdo, 'quiz_attempts', 'country');
    $hasAttemptCity = db_has_column($this->pdo, 'quiz_attempts', 'city');
    $hasAttemptAttempted = db_has_column($this->pdo, 'quiz_attempts', 'attempted_questions_count');
    $hasAttemptFlagged = db_has_column($this->pdo, 'quiz_attempts', 'flagged_questions_count');
    $hasAttemptEndBtn = db_has_column($this->pdo, 'quiz_attempts', 'ended_via_button');
    $hasAttemptTime = db_has_column($this->pdo, 'quiz_attempts', 'total_time_seconds');
    $hasAttemptFirstOpened = db_has_column($this->pdo, 'quiz_attempts', 'first_question_opened_at');
    $hasAttemptFirstIndex = db_has_column($this->pdo, 'quiz_attempts', 'first_question_index');
    $hasAttemptLastActivity = db_has_column($this->pdo, 'quiz_attempts', 'last_activity_at');
    $hasAttemptResume = db_has_column($this->pdo, 'quiz_attempts', 'resume_count');
    $hasAccessUserId = db_has_column($this->pdo, 'access_codes', 'user_id');
    $hasAccessExamName = db_has_column($this->pdo, 'access_codes', 'exam_name');
    $hasUserFullName = db_has_column($this->pdo, 'admin_users', 'full_name');

    $userJoinExpr = $hasAttemptUserId ? 'qa.user_id' : ($hasAccessUserId ? 'ac.user_id' : 'NULL');
    $auFullNameSelect = $hasUserFullName ? 'au.full_name' : 'NULL';
    $pctSelect = $hasAttemptPct ? 'qa.percentage' : '(CASE WHEN COALESCE(qa.max_score,0) > 0 THEN ROUND((qa.score / qa.max_score) * 100, 2) ELSE NULL END)';
    $statusSelect = $hasAttemptStatus ? 'qa.result_status' : '(CASE WHEN qa.finished_at IS NULL THEN NULL ELSE "completed" END)';
    $modeSelect = $hasAttemptMode ? 'qa.exam_mode' : '"exam"';
    $ipSelect = $hasAttemptIp ? 'qa.ip_address' : 'NULL';
    $countrySelect = $hasAttemptCountry ? 'qa.country' : 'NULL';
    $citySelect = $hasAttemptCity ? 'qa.city' : 'NULL';
    $attemptedSelect = $hasAttemptAttempted ? 'qa.attempted_questions_count' : '0';
    $flaggedSelect = $hasAttemptFlagged ? 'qa.flagged_questions_count' : '0';
    $endedViaButtonSelect = $hasAttemptEndBtn ? 'qa.ended_via_button' : '0';
    $totalTimeSelect = $hasAttemptTime ? 'qa.total_time_seconds' : 'TIMESTAMPDIFF(SECOND, qa.started_at, COALESCE(qa.finished_at, UTC_TIMESTAMP()))';
    $firstOpenedSelect = $hasAttemptFirstOpened ? 'qa.first_question_opened_at' : 'qa.started_at';
    $firstIndexSelect = $hasAttemptFirstIndex ? 'qa.first_question_index' : '1';
    $lastActivitySelect = $hasAttemptLastActivity ? 'qa.last_activity_at' : 'COALESCE(qa.finished_at, qa.started_at)';
    $resumeCountSelect = $hasAttemptResume ? 'qa.resume_count' : '0';
    $examNameParts = [];
    if ($hasExamsTable) $examNameParts[] = 'NULLIF(e.exam_name, "")';
    if ($hasAccessExamName) $examNameParts[] = 'NULLIF(ac.exam_name, "")';
    $examNameParts[] = 'NULLIF(qa.exam_id, "")';
    $examTitleSelect = 'COALESCE(' . implode(', ', $examNameParts) . ')';
    $dateExpr = 'COALESCE(qa.finished_at, qa.started_at)';

    $where = [];
    $args = [];
    if ($examFilter !== '') { $where[] = 'qa.exam_id = ?'; $args[] = $examFilter; }
    if ($statusFilter === 'finished') { $where[] = 'qa.finished_at IS NOT NULL'; }
    if ($statusFilter === 'pending') { $where[] = 'qa.finished_at IS NULL'; }
    if ($modeFilter !== '' && $hasAttemptMode) { $where[] = 'qa.exam_mode = ?'; $args[] = $modeFilter; }
    if ($q !== '') {
      $searchParts = ['qa.user_name LIKE ?', "COALESCE(au.username,'') LIKE ?", "COALESCE(ac.code_plain,'') LIKE ?", 'qa.exam_id LIKE ?'];
      if ($hasUserFullName) $searchParts[] = "COALESCE(au.full_name,'') LIKE ?";
      if ($hasAttemptIp) $searchParts[] = "COALESCE(qa.ip_address,'') LIKE ?";
      if ($hasAttemptCountry) $searchParts[] = "COALESCE(qa.country,'') LIKE ?";
      if ($hasAttemptCity) $searchParts[] = "COALESCE(qa.city,'') LIKE ?";
      $where[] = '(' . implode(' OR ', $searchParts) . ')';
      $repeat = 4 + ($hasUserFullName ? 1 : 0) + ($hasAttemptIp ? 1 : 0) + ($hasAttemptCountry ? 1 : 0) + ($hasAttemptCity ? 1 : 0);
      for ($i=0; $i<$repeat; $i++) $args[] = '%' . $q . '%';
    }

    $fromDateTime = self::normalizeDate($fromDate, '00:00:00');
    $toDateTime = self::normalizeDate($toDate, '23:59:59');
    if ($fromDateTime) { $where[] = "{$dateExpr} >= ?"; $args[] = $fromDateTime; }
    if ($toDateTime) { $where[] = "{$dateExpr} <= ?"; $args[] = $toDateTime; }
    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $examJoin = $hasExamsTable ? 'LEFT JOIN exams e ON e.exam_id = qa.exam_id' : '';
    $fromSql = "FROM quiz_attempts qa
        {$examJoin}
        LEFT JOIN access_codes ac ON ac.id = qa.access_code_id
        LEFT JOIN admin_users au ON au.id = {$userJoinExpr}
        {$sqlWhere}";

    $countSql = "SELECT COUNT(*) {$fromSql}";
    $countSt = $this->pdo->prepare($countSql);
    $countSt->execute($args);
    $totalRows = (int)$countSt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;

    $sql = "SELECT qa.id, qa.exam_id, qa.access_code_id, qa.user_name, qa.started_at, qa.finished_at, qa.score, qa.max_score,
               {$pctSelect} AS percentage, {$statusSelect} AS result_status, {$modeSelect} AS exam_mode, {$ipSelect} AS ip_address,
               {$countrySelect} AS country, {$citySelect} AS city, {$attemptedSelect} AS attempted_questions_count, {$flaggedSelect} AS flagged_questions_count,
               {$endedViaButtonSelect} AS ended_via_button, {$totalTimeSelect} AS total_time_seconds, {$firstOpenedSelect} AS first_question_opened_at,
               {$firstIndexSelect} AS first_question_index, {$lastActivitySelect} AS last_activity_at, {$resumeCountSelect} AS resume_count,
               {$examTitleSelect} AS exam_title, ac.code_plain, au.username AS linked_username, {$auFullNameSelect} AS linked_full_name
        {$fromSql}
        ORDER BY {$dateExpr} DESC, qa.id DESC
        LIMIT {$perPage} OFFSET {$offset}";
    $st = $this->pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll() ?: [];

    return [
      'rows' => $rows,
      'totalRows' => $totalRows,
      'totalPages' => $totalPages,
      'page' => $page,
      'perPage' => $perPage,
      'offset' => $offset,
      'filters' => [
        'q' => $q,
        'exam_id' => $examFilter,
        'status' => $statusFilter,
        'mode' => $modeFilter,
        'range' => $datePreset,
        'from' => $fromDate,
        'to' => $toDate,
        'per_page' => $perPage,
        'page' => $page,
      ],
      'meta' => [
        'allowedPerPage' => $allowedPerPage,
        'hasExamsTable' => $hasExamsTable,
        'hasAttemptMode' => $hasAttemptMode,
      ],
    ];
  }

  public static function normalizeDate(?string $value, string $time = '00:00:00'): ?string {
    $value = trim((string)$value);
    if ($value === '') return null;
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $value . ' ' . $time);
    if ($dt instanceof DateTime) return $dt->format('Y-m-d H:i:s');
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt instanceof DateTime ? $dt->format('Y-m-d ' . $time) : null;
  }

  /** @return array{0:string,1:string} */
  public static function rangeDates(string $preset): array {
    $today = new DateTime('today');
    $start = null;
    $end = null;
    switch ($preset) {
      case 'today': $start = clone $today; $end = clone $today; break;
      case 'yesterday': $start = (clone $today)->modify('-1 day'); $end = clone $start; break;
      case 'last7': $start = (clone $today)->modify('-6 days'); $end = clone $today; break;
      case 'last15': $start = (clone $today)->modify('-14 days'); $end = clone $today; break;
      case 'last30': $start = (clone $today)->modify('-29 days'); $end = clone $today; break;
      case 'this_month': $start = new DateTime(date('Y-m-01')); $end = (clone $start)->modify('last day of this month'); break;
      case 'past_month': $start = new DateTime(date('Y-m-01', strtotime('first day of last month'))); $end = new DateTime(date('Y-m-t', strtotime('last month'))); break;
      case 'last3months': $start = new DateTime(date('Y-m-01', strtotime('-2 months'))); $end = clone $today; break;
      case 'this_year': $start = new DateTime(date('Y-01-01')); $end = new DateTime(date('Y-12-31')); break;
      default: break;
    }
    return [$start ? $start->format('Y-m-d') : '', $end ? $end->format('Y-m-d') : ''];
  }
}
