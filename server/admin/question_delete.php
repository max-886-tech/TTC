<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/quiz.php';

require_permission('quiz.manage');

$pdo = db();
quiz_safe_ensure_schema($pdo);

$id = (int)($_GET['id'] ?? 0);
$exam_id = trim($_GET['exam_id'] ?? '');

if ($id > 0) {
  $pdo->beginTransaction();
  try {
    $del = $pdo->prepare("DELETE FROM quiz_questions WHERE id=?");
    $del->execute([$id]);
    if ($exam_id !== '') {
      quiz_invalidate_exam_attempts($pdo, $exam_id);
    }
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
  }
}

header("Location: /admin/questions.php?exam_id=" . urlencode($exam_id));
exit;
