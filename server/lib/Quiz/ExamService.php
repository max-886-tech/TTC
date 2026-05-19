<?php
function quiz_upsert_exam(PDO $pdo, string $exam_id, string $exam_name, array $opts = []): void {
  quiz_safe_ensure_schema($pdo);
  $resource_type = $opts['resource_type'] ?? 'quiz';
  $duration_minutes = isset($opts['duration_minutes']) ? (int)$opts['duration_minutes'] : 30;
  $shuffle_questions = isset($opts['shuffle_questions']) ? (int)!!$opts['shuffle_questions'] : 1;
  $shuffle_choices = isset($opts['shuffle_choices']) ? (int)!!$opts['shuffle_choices'] : 1;

  $stmt = $pdo->prepare("INSERT INTO exams (exam_id, exam_name, resource_type, duration_minutes, shuffle_questions, shuffle_choices, updated_at)
    VALUES (?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
      exam_name=VALUES(exam_name),
      resource_type=VALUES(resource_type),
      duration_minutes=VALUES(duration_minutes),
      shuffle_questions=VALUES(shuffle_questions),
      shuffle_choices=VALUES(shuffle_choices),
      updated_at=VALUES(updated_at)");
  $stmt->execute([$exam_id, $exam_name, $resource_type, $duration_minutes, $shuffle_questions, $shuffle_choices, utc_now_sql()]);
}


function quiz_get_exam(PDO $pdo, string $exam_id): ?array {
  quiz_safe_ensure_schema($pdo);
  $stmt = $pdo->prepare("SELECT * FROM exams WHERE exam_id=? LIMIT 1");
  $stmt->execute([$exam_id]);
  $row = $stmt->fetch();
  return $row ?: null;
}


function quiz_list_exams(PDO $pdo): array {
  quiz_safe_ensure_schema($pdo);
  $stmt = $pdo->query("SELECT * FROM exams ORDER BY created_at DESC, id DESC");
  return $stmt->fetchAll() ?: [];
}



