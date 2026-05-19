<?php
function quiz_find_unfinished_attempt(PDO $pdo, string $exam_id, int $access_code_id, ?string $device_hash, ?string $exam_mode = null): ?array {
  quiz_safe_ensure_schema($pdo);
  if ($device_hash !== null && $device_hash !== '') {
    if ($exam_mode !== null && $exam_mode !== '') {
      $stmt = $pdo->prepare("SELECT * FROM quiz_attempts WHERE exam_id=? AND access_code_id=? AND exam_mode=? AND finished_at IS NULL AND device_hash=? ORDER BY id DESC LIMIT 1");
      $stmt->execute([$exam_id, $access_code_id, $exam_mode, $device_hash]);
    } else {
      $stmt = $pdo->prepare("SELECT * FROM quiz_attempts WHERE exam_id=? AND access_code_id=? AND finished_at IS NULL AND device_hash=? ORDER BY id DESC LIMIT 1");
      $stmt->execute([$exam_id, $access_code_id, $device_hash]);
    }
  } else {
    if ($exam_mode !== null && $exam_mode !== '') {
      $stmt = $pdo->prepare("SELECT * FROM quiz_attempts WHERE exam_id=? AND access_code_id=? AND exam_mode=? AND finished_at IS NULL ORDER BY id DESC LIMIT 1");
      $stmt->execute([$exam_id, $access_code_id, $exam_mode]);
    } else {
      $stmt = $pdo->prepare("SELECT * FROM quiz_attempts WHERE exam_id=? AND access_code_id=? AND finished_at IS NULL ORDER BY id DESC LIMIT 1");
      $stmt->execute([$exam_id, $access_code_id]);
    }
  }
  $row = $stmt->fetch();
  return $row ?: null;
}


function quiz_decode_json_array($json, array $fallback = []): array {
  if (!is_string($json) || trim($json) === '') return $fallback;
  $data = json_decode($json, true);
  return is_array($data) ? $data : $fallback;
}

function quiz_matrix_options_from_storage($value): array {
  if (is_array($value)) {
    return array_values(array_filter(array_map('trim', $value), fn($v) => $v !== ''));
  }
  $raw = trim((string)$value);
  if ($raw === '') return [];
  $decoded = json_decode($raw, true);
  if (is_array($decoded)) {
    return array_values(array_filter(array_map('trim', $decoded), fn($v) => $v !== ''));
  }
  $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
  return array_values(array_filter(array_map('trim', $lines), fn($v) => $v !== ''));
}


function quiz_build_attempt_payload(array $exam, array $questions, ?array $modeCfg = null): array {
  $modeCfg = is_array($modeCfg) ? $modeCfg : quiz_get_mode_config($exam, 'exam');
  $shuffleQ = (int)($modeCfg['shuffle_questions'] ?? ($exam['shuffle_questions'] ?? 1)) === 1;
  $shuffleC = (int)($modeCfg['shuffle_choices'] ?? ($exam['shuffle_choices'] ?? 1)) === 1;

  if ($shuffleQ) shuffle($questions);
  $questions = quiz_limit_questions_for_mode($questions, $modeCfg);

  $outQuestions = [];
  foreach ($questions as $q) {
    $choices = $q['choices'] ?? [];
    $qType = (string)($q['question_type'] ?? 'single');
    $qMode = quiz_normalize_drag_drop_mode((string)($q['drag_drop_mode'] ?? 'categorize'));
    $qCfg = quiz_question_drag_config($q);
    $isImageSliceCategorize = ($qType === 'drag_drop' && $qMode === 'categorize' && true);

    // Important: cropped-image drag/drop pieces depend on the original piece order
    // matching the saved quiz_choices order. If we shuffle choices here, the visual
    // slice shown for "Piece 1" can get attached to the wrong choice ID, which makes
    // correct-looking answers grade as wrong. Keep original order for image slices.
    if ($shuffleC && !$isImageSliceCategorize) shuffle($choices);

    $outChoices = [];
    foreach ($choices as $c) {
      $outChoices[] = [
        'id' => (int)$c['id'],
        'text' => (string)$c['choice_text'],
        'image' => (string)($c['choice_image'] ?? ''),
        // For yes/no questions, is_correct means the statement's correct answer is Yes.
        // If it is 0, the correct answer for that statement is No.
        'is_correct' => !empty($c['is_correct']) ? 1 : 0,
        'yes_no_correct' => !empty($c['is_correct']) ? 'yes' : 'no',
        'matrix_options' => quiz_matrix_options_from_storage($c['drag_blank_key'] ?? ''),
        'matrix_correct_index' => isset($c['drag_target_index']) && $c['drag_target_index'] !== null ? (int)$c['drag_target_index'] : 0,
        'drag_target_index' => isset($c['drag_target_index']) && $c['drag_target_index'] !== null ? (int)$c['drag_target_index'] : null,
        'drag_blank_key' => (string)($c['drag_blank_key'] ?? ''),
        'is_distractor' => !empty($c['is_distractor']) ? 1 : 0,
      ];
    }

    $outQuestions[] = [
      'id' => (int)$q['id'],
      'text' => (string)$q['question_text'],
      'case_study' => (string)($q['case_study_text'] ?? ''),
      'image' => (string)($q['question_image'] ?? ''),
      'explanation' => (string)($q['explanation_text'] ?? ''),
      'type' => (string)$q['question_type'],
      'drag_drop_mode' => quiz_normalize_drag_drop_mode((string)($q['drag_drop_mode'] ?? 'categorize')),
      'drag_drop_config' => quiz_question_drag_config($q),
      'points' => (int)$q['points'],
      'choices' => $outChoices,
    ];
  }

  return $outQuestions;
}



function quiz_invalidate_exam_attempts(PDO $pdo, string $exam_id): void {
  // Avoid runtime DDL while an outer transaction is open.
  // quiz_ensure_schema() can run ALTER TABLE statements on older installs,
  // and MySQL DDL may implicitly commit, which breaks the caller transaction.
  quiz_safe_ensure_schema($pdo);

  $stmt = $pdo->prepare("SELECT id FROM quiz_attempts WHERE exam_id=? AND finished_at IS NULL");
  $stmt->execute([$exam_id]);
  $attemptIds = array_map(fn($v) => (int)$v, $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

  if ($attemptIds) {
    $delAns = $pdo->prepare("DELETE FROM quiz_answers WHERE attempt_id=?");
    foreach ($attemptIds as $attemptId) {
      $delAns->execute([$attemptId]);
    }
  }

  $upd = $pdo->prepare("UPDATE quiz_attempts SET payload_json=NULL, state_json=NULL WHERE exam_id=? AND finished_at IS NULL");
  $upd->execute([$exam_id]);
}


function quiz_save_attempt_state(PDO $pdo, int $attempt_id, array $state): void {
  quiz_safe_ensure_schema($pdo);
  $stmt = $pdo->prepare("UPDATE quiz_attempts SET state_json=? WHERE id=?");
  $stmt->execute([json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $attempt_id]);
}


function quiz_count_attempted_questions_from_state(array $state): int {
  $answers = is_array($state['answers'] ?? null) ? $state['answers'] : [];
  $count = 0;
  foreach ($answers as $answer) {
    if (is_array($answer)) {
      if (!empty($answer)) $count++;
      continue;
    }
    if (is_object($answer)) {
      if (count((array)$answer) > 0) $count++;
      continue;
    }
    if ($answer !== null && $answer !== '' && $answer !== false) $count++;
  }
  return $count;
}


function quiz_count_flagged_questions_from_state(array $state): int {
  $flagged = is_array($state['flagged'] ?? null) ? $state['flagged'] : [];
  $count = 0;
  foreach ($flagged as $value) {
    if (!empty($value)) $count++;
  }
  return $count;
}


function quiz_calculate_total_time_seconds(array $attempt, ?string $finishedAt = null): ?int {
  $startedAt = (string)($attempt['started_at'] ?? '');
  $finishedAt = $finishedAt ?: (string)($attempt['finished_at'] ?? '');
  if ($startedAt === '' || $finishedAt === '') return null;
  try {
    $start = new DateTime($startedAt, new DateTimeZone('UTC'));
    $end = new DateTime($finishedAt, new DateTimeZone('UTC'));
    $diff = $end->getTimestamp() - $start->getTimestamp();
    return $diff >= 0 ? $diff : 0;
  } catch (Throwable $e) {
    return null;
  }
}


function quiz_get_attempt_answers(PDO $pdo, int $attempt_id): array {
  $stmt = $pdo->prepare("SELECT question_id, choice_id FROM quiz_answers WHERE attempt_id=? ORDER BY question_id, choice_id");
  $stmt->execute([$attempt_id]);
  $rows = $stmt->fetchAll() ?: [];
  $out = [];
  foreach ($rows as $r) {
    $qid = (string)(int)$r['question_id'];
    if (!isset($out[$qid])) $out[$qid] = [];
    $out[$qid][] = (int)$r['choice_id'];
  }
  return $out;
}


function quiz_grade_attempt(PDO $pdo, int $attempt_id): array {
  // returns [score, max_score]
  $stmt = $pdo->prepare("SELECT * FROM quiz_attempts WHERE id=? LIMIT 1");
  $stmt->execute([$attempt_id]);
  $attempt = $stmt->fetch();
  if (!$attempt) return ['score' => 0, 'max_score' => 0];

  $exam_id = (string)$attempt['exam_id'];

  // Grade against the exact payload the user actually received when the attempt started.
  // This prevents mismatches when question choices were shuffled, reordered, edited,
  // or rebuilt after the attempt payload was created.
  $questions = quiz_decode_json_array((string)($attempt['payload_json'] ?? ''), []);
  if (!$questions) {
    $exam = quiz_get_exam($pdo, $exam_id) ?: [];
    $questions = quiz_build_attempt_payload($exam, quiz_get_questions($pdo, $exam_id));
  }

  // Fetch answers for this attempt
  $stmtA = $pdo->prepare("SELECT question_id, choice_id FROM quiz_answers WHERE attempt_id=?");
  $stmtA->execute([$attempt_id]);
  $ansRows = $stmtA->fetchAll() ?: [];
  $user = [];
  foreach ($ansRows as $r) {
    $qid = (int)$r['question_id'];
    $cid = (int)$r['choice_id'];
    if (!isset($user[$qid])) $user[$qid] = [];
    $user[$qid][] = $cid;
  }

  $state = quiz_decode_json_array((string)($attempt['state_json'] ?? ''), []);
  $stateAnswers = is_array($state['answers'] ?? null) ? $state['answers'] : [];

  $score = 0;
  $max = 0;

  foreach ($questions as $q) {
    $qid = (int)($q['id'] ?? 0);
    if ($qid <= 0) continue;

    $points = max(0, (int)($q['points'] ?? 0));
    $max += $points;

    if ((string)($q['type'] ?? $q['question_type'] ?? '') === 'drag_drop') {
      $answer = $stateAnswers[(string)$qid] ?? [];
      $gradeQ = [
        'id' => $qid,
        'question_type' => 'drag_drop',
        'drag_drop_mode' => (string)($q['drag_drop_mode'] ?? 'categorize'),
        'drag_drop_config_json' => json_encode((array)($q['drag_drop_config'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'choices' => [],
      ];
      foreach ((array)($q['choices'] ?? []) as $c) {
        $gradeQ['choices'][] = [
          'id' => (int)($c['id'] ?? 0),
          'is_correct' => (int)($c['is_correct'] ?? 0),
          'drag_target_index' => isset($c['drag_target_index']) ? (int)$c['drag_target_index'] : null,
          'drag_blank_key' => (string)($c['drag_blank_key'] ?? ''),
          'is_distractor' => !empty($c['is_distractor']) ? 1 : 0,
        ];
      }
      if (quiz_grade_drag_drop_question($gradeQ, $answer)) {
        $score += $points;
      }
      continue;
    }

    if ((string)($q['type'] ?? $q['question_type'] ?? '') === 'yes_no') {
      $answer = $stateAnswers[(string)$qid] ?? [];
      $answerMap = is_array($answer) ? $answer : [];
      $allAnswered = true;
      $isMatch = true;
      foreach ((array)($q['choices'] ?? []) as $c) {
        $cid = (int)($c['id'] ?? 0);
        if ($cid <= 0) continue;
        $raw = $answerMap[(string)$cid] ?? $answerMap[$cid] ?? null;
        if ($raw === null || $raw === '') {
          $allAnswered = false;
          $isMatch = false;
          break;
        }
        $normalized = ((string)$raw === '1' || (string)$raw === 'yes') ? 'yes' : (((string)$raw === '0' || (string)$raw === '2' || (string)$raw === 'no') ? 'no' : null);
        if ($normalized === null) {
          $allAnswered = false;
          $isMatch = false;
          break;
        }
        $expected = !empty($c['is_correct']) ? 'yes' : 'no';
        if ($normalized !== $expected) $isMatch = false;
      }
      if ($allAnswered && $isMatch && count((array)($q['choices'] ?? [])) > 0) {
        $score += $points;
      }
      continue;
    }
    if ((string)($q['type'] ?? $q['question_type'] ?? '') === 'dropdown_matrix') {
      $answer = $stateAnswers[(string)$qid] ?? [];
      $answerMap = is_array($answer) ? $answer : [];
      $allAnswered = true;
      $isMatch = true;
      foreach ((array)($q['choices'] ?? []) as $c) {
        $cid = (int)($c['id'] ?? 0);
        if ($cid <= 0) continue;
        $raw = $answerMap[(string)$cid] ?? $answerMap[$cid] ?? null;
        $selectedIndex = (int)$raw;
        $expectedIndex = (int)($c['matrix_correct_index'] ?? $c['drag_target_index'] ?? 0);
        if ($selectedIndex <= 0) {
          $allAnswered = false;
          $isMatch = false;
          break;
        }
        if ($selectedIndex !== $expectedIndex) $isMatch = false;
      }
      if ($allAnswered && $isMatch && count((array)($q['choices'] ?? [])) > 0) {
        $score += $points;
      }
      continue;
    }

    $correct = [];
    foreach ((array)($q['choices'] ?? []) as $c) {
      if ((int)($c['is_correct'] ?? 0) === 1) $correct[] = (int)$c['id'];
    }
    sort($correct);
    $chosen = $user[$qid] ?? [];
    $chosen = array_values(array_unique(array_map('intval', $chosen)));
    sort($chosen);

    if ($correct === $chosen && count($correct) > 0) {
      $score += $points;
    }
  }

  return ['score' => $score, 'max_score' => $max];
}



