<?php
function quiz_review_matrix_options_from_storage($value): array {
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

function quiz_attempt_review(PDO $pdo, int $attempt_id): array {
  quiz_safe_ensure_schema($pdo);

  $stmt = $pdo->prepare("SELECT * FROM quiz_attempts WHERE id=? LIMIT 1");
  $stmt->execute([$attempt_id]);
  $attempt = $stmt->fetch();
  if (!$attempt) return [];

  $questions = quiz_decode_json_array((string)($attempt['payload_json'] ?? ''), []);
  if (!$questions) {
    $exam = quiz_get_exam($pdo, (string)$attempt['exam_id']) ?: [];
    $questions = quiz_build_attempt_payload($exam, quiz_get_questions($pdo, (string)$attempt['exam_id']));
  }

  $stmtA = $pdo->prepare("SELECT question_id, choice_id FROM quiz_answers WHERE attempt_id=? ORDER BY question_id ASC, choice_id ASC");
  $stmtA->execute([$attempt_id]);
  $ansRows = $stmtA->fetchAll() ?: [];
  $userChoiceIds = [];
  foreach ($ansRows as $r) {
    $qid = (int)$r['question_id'];
    $cid = (int)$r['choice_id'];
    if (!isset($userChoiceIds[$qid])) $userChoiceIds[$qid] = [];
    $userChoiceIds[$qid][] = $cid;
  }

  $state = quiz_decode_json_array((string)($attempt['state_json'] ?? ''), []);
  $stateAnswers = is_array($state['answers'] ?? null) ? $state['answers'] : [];

  $items = [];
  $num = 0;
  foreach ($questions as $q) {
    $num++;
    $qid = (int)($q['id'] ?? 0);
    $qtype = (string)($q['type'] ?? $q['question_type'] ?? 'single');
    $choices = (array)($q['choices'] ?? []);

    $correctChoiceIds = [];
    $correctTexts = [];
    $choiceTextById = [];
    foreach ($choices as $c) {
      $cid = (int)($c['id'] ?? 0);
      $txt = trim(strip_tags((string)($c['text'] ?? $c['choice_text'] ?? '')));
      $choiceTextById[$cid] = $txt;
      if (!empty($c['is_correct'])) {
        $correctChoiceIds[] = $cid;
        if ($txt !== '') $correctTexts[] = $txt;
      }
    }

    $userAnswerText = '';
    $correctAnswerText = implode(', ', $correctTexts);
    $isCorrect = false;

    if ($qtype === 'drag_drop') {
      $answer = $stateAnswers[(string)$qid] ?? [];
      $isCorrect = quiz_grade_drag_drop_question([
        'id' => $qid,
        'question_type' => 'drag_drop',
        'drag_drop_mode' => (string)($q['drag_drop_mode'] ?? 'categorize'),
        'drag_drop_config_json' => json_encode((array)($q['drag_drop_config'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'choices' => array_map(function($c){
          return [
            'id' => (int)($c['id'] ?? 0),
            'choice_text' => (string)($c['text'] ?? $c['choice_text'] ?? ''),
            'is_correct' => !empty($c['is_correct']) ? 1 : 0,
            'drag_target_index' => isset($c['drag_target_index']) ? (int)$c['drag_target_index'] : null,
            'drag_blank_key' => (string)($c['drag_blank_key'] ?? ''),
            'is_distractor' => !empty($c['is_distractor']) ? 1 : 0,
          ];
        }, $choices),
      ], $answer);
      $userAnswerText = is_array($answer) ? json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string)$answer;
      if ($correctAnswerText === '') $correctAnswerText = 'Correct drag-drop arrangement';
    } elseif ($qtype === 'yes_no') {
      $answer = $stateAnswers[(string)$qid] ?? [];
      $answerMap = is_array($answer) ? $answer : [];
      $rows = [];
      $allAnswered = true;
      $isCorrect = true;
      foreach ($choices as $c) {
        $cid = (int)($c['id'] ?? 0);
        $statement = trim(strip_tags((string)($c['text'] ?? $c['choice_text'] ?? '')));
        $expected = !empty($c['is_correct']) ? 'Yes' : 'No';
        $raw = $answerMap[(string)$cid] ?? $answerMap[$cid] ?? null;
        $selectedText = ($raw === null || $raw === '') ? '' : (((string)$raw === '1' || (string)$raw === 'yes') ? 'Yes' : 'No');
        if ($selectedText === '') $allAnswered = false;
        if ($selectedText === '' || $selectedText !== $expected) $isCorrect = false;
        $rows[] = [
          'statement' => $statement,
          'your_answer' => $selectedText,
          'correct_answer' => $expected,
          'is_correct' => ($selectedText !== '' && $selectedText === $expected) ? 1 : 0,
        ];
      }
      $userAnswerText = $allAnswered ? 'Answered all statements' : 'Some statements not answered';
      $correctAnswerText = 'See statement table below';
      $items[] = [
        'number' => $num,
        'question_id' => $qid,
        'type' => $qtype,
        'question_text' => (string)($q['text'] ?? $q['question_text'] ?? ''),
        'case_study_text' => (string)($q['case_study'] ?? $q['case_study_text'] ?? ''),
        'question_image' => (string)($q['image'] ?? $q['question_image'] ?? ''),
        'user_answer' => $userAnswerText,
        'correct_answer' => $correctAnswerText,
        'yes_no_rows' => $rows,
        'explanation' => (string)($q['explanation'] ?? $q['explanation_text'] ?? ''),
        'is_correct' => ($allAnswered && $isCorrect) ? 1 : 0,
      ];
      continue;
        } elseif ($qtype === 'dropdown_matrix') {
      $answer = $stateAnswers[(string)$qid] ?? [];
      $answerMap = is_array($answer) ? $answer : [];
      $rows = [];
      $allAnswered = true;
      $isCorrect = true;
      foreach ($choices as $c) {
        $cid = (int)($c['id'] ?? 0);
        $statement = trim(strip_tags((string)($c['text'] ?? $c['choice_text'] ?? '')));
        $options = quiz_review_matrix_options_from_storage($c['matrix_options'] ?? ($c['drag_blank_key'] ?? ''));
        $expectedIndex = (int)($c['matrix_correct_index'] ?? $c['drag_target_index'] ?? 0);
        $expected = ($expectedIndex > 0 && isset($options[$expectedIndex - 1])) ? $options[$expectedIndex - 1] : '';
        $raw = $answerMap[(string)$cid] ?? $answerMap[$cid] ?? null;
        $selectedIndex = (int)$raw;
        $selectedText = ($selectedIndex > 0 && isset($options[$selectedIndex - 1])) ? $options[$selectedIndex - 1] : '';
        if ($selectedText === '') $allAnswered = false;
        if ($selectedText === '' || $selectedIndex !== $expectedIndex) $isCorrect = false;
        $rows[] = [
          'statement' => $statement,
          'your_answer' => $selectedText,
          'correct_answer' => $expected,
          'is_correct' => ($selectedText !== '' && $selectedIndex === $expectedIndex) ? 1 : 0,
        ];
      }
      $userAnswerText = $allAnswered ? 'Answered all statements' : 'Some statements not answered';
      $correctAnswerText = 'See statement table below';
      $items[] = [
        'number' => $num,
        'question_id' => $qid,
        'type' => $qtype,
        'question_text' => (string)($q['text'] ?? $q['question_text'] ?? ''),
        'case_study_text' => (string)($q['case_study'] ?? $q['case_study_text'] ?? ''),
        'question_image' => (string)($q['image'] ?? $q['question_image'] ?? ''),
        'user_answer' => $userAnswerText,
        'correct_answer' => $correctAnswerText,
        'yes_no_rows' => $rows,
        'explanation' => (string)($q['explanation'] ?? $q['explanation_text'] ?? ''),
        'is_correct' => ($allAnswered && $isCorrect) ? 1 : 0,
      ];
      continue;
    } else {
      $selected = $userChoiceIds[$qid] ?? [];
      $userTexts = [];
      foreach ($selected as $cid) {
        if (isset($choiceTextById[$cid]) && $choiceTextById[$cid] !== '') $userTexts[] = $choiceTextById[$cid];
      }
      $userAnswerText = implode(', ', $userTexts);
      sort($selected);
      $correctSorted = $correctChoiceIds;
      sort($correctSorted);
      $isCorrect = ($selected === $correctSorted);
    }

    $items[] = [
      'number' => $num,
      'question_id' => $qid,
      'type' => $qtype,
      'question_text' => (string)($q['text'] ?? $q['question_text'] ?? ''),
      'case_study_text' => (string)($q['case_study'] ?? $q['case_study_text'] ?? ''),
      'question_image' => (string)($q['image'] ?? $q['question_image'] ?? ''),
      'user_answer' => $userAnswerText,
      'correct_answer' => $correctAnswerText,
      'explanation' => (string)($q['explanation'] ?? $q['explanation_text'] ?? ''),
      'is_correct' => $isCorrect ? 1 : 0,
    ];
  }

  return $items;
}

