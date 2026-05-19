<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

final class AjaxExamSearchController {
  public static function handle(): void {
    require_permission('code.generate');
    header('Content-Type: application/json; charset=utf-8');

    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '' || strlen($q) < 2) {
      echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_SLASHES);
      return;
    }

    $pdo = db();
    if (function_exists('quiz_ensure_schema') && !$pdo->inTransaction()) {
      quiz_safe_ensure_schema($pdo);
    }

    $like = '%' . $q . '%';
    $sql = "SELECT e.exam_id, e.exam_name,
                   COUNT(q.id) AS questions
            FROM exams e
            LEFT JOIN quiz_questions q ON q.exam_id = e.exam_id AND q.is_active = 1
            WHERE e.resource_type = 'quiz'
              AND (e.exam_id LIKE ? OR e.exam_name LIKE ?)
            GROUP BY e.exam_id, e.exam_name
            ORDER BY e.exam_name ASC, e.exam_id ASC
            LIMIT 25";
    $st = $pdo->prepare($sql);
    $st->execute([$like, $like]);
    $rows = $st->fetchAll() ?: [];

    $items = array_map(static function(array $r): array {
      return [
        'exam_id' => (string)($r['exam_id'] ?? ''),
        'exam_name' => (string)($r['exam_name'] ?? ''),
        'questions' => (int)($r['questions'] ?? 0),
      ];
    }, $rows);

    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_SLASHES);
  }
}
