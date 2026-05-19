<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/quiz.php';

require_permission('code.generate');

header('Content-Type: application/json; charset=utf-8');

$q = trim((string)($_GET['q'] ?? ''));

$pdo = db();
quiz_safe_ensure_schema($pdo);

// TTC (Reader) exams only
if ($q === '' || strlen($q) < 2) {
  $stmt = $pdo->prepare("SELECT e.exam_id, e.exam_name,
      CASE WHEN (COALESCE(NULLIF(TRIM(e.r2_key),''), '') <> '' OR COALESCE(NULLIF(TRIM(e.r2_url),''), '') <> '') THEN 1 ELSE 0 END AS has_r2
    FROM exams e
    WHERE e.resource_type='enbl'
    ORDER BY e.updated_at DESC, e.created_at DESC, e.exam_id ASC
    LIMIT 25");
  $stmt->execute();
} else {
  $stmt = $pdo->prepare("SELECT e.exam_id, e.exam_name,
      CASE WHEN (COALESCE(NULLIF(TRIM(e.r2_key),''), '') <> '' OR COALESCE(NULLIF(TRIM(e.r2_url),''), '') <> '') THEN 1 ELSE 0 END AS has_r2
    FROM exams e
    WHERE e.resource_type='enbl'
      AND (e.exam_id LIKE :q1 OR e.exam_name LIKE :q2)
    ORDER BY e.exam_id ASC
    LIMIT 25");
  $like = '%' . $q . '%';
  $stmt->execute([':q1' => $like, ':q2' => $like]);
}

$items = $stmt->fetchAll() ?: [];
echo json_encode(['ok' => true, 'items' => $items]);
