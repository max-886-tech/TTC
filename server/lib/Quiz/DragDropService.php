<?php
function quiz_normalize_drag_drop_mode(?string $mode): string {
  return 'categorize';
}

function quiz_decode_json_object($json, array $fallback = []): array {
  if (!is_string($json) || trim($json) === '') return $fallback;
  $data = json_decode($json, true);
  return is_array($data) ? $data : $fallback;
}

function quiz_question_drag_config(array $q): array {
  $cfg = quiz_decode_json_object((string)($q['drag_drop_config_json'] ?? ''), []);
  $cfg['mode'] = 'categorize';
  $cfg['visual_mode'] = 'image_slices';
  $cfg['slot_count'] = max(1, count((array)($cfg['targets'] ?? [])));
  $cfg['exact_slot_match'] = 1;
  return $cfg;
}

function quiz_drag_answer_values($answer): array {
  if (is_array($answer)) {
    $vals = [];
    foreach ($answer as $v) {
      if (is_array($v)) continue;
      $n = (int)$v;
      if ($n > 0) $vals[] = $n;
    }
    return array_values(array_unique($vals));
  }
  $n = (int)$answer;
  return $n > 0 ? [$n] : [];
}

function quiz_drag_answer_map($answer): array {
  if (!is_array($answer)) return [];
  $out = [];
  foreach ($answer as $k => $v) {
    $k = trim((string)$k);
    $n = (int)$v;
    if ($k !== '' && $n > 0) $out[$k] = $n;
  }
  return $out;
}

function quiz_grade_drag_drop_question(array $q, $answer): bool {
  $cfg = quiz_question_drag_config($q);
  $correctMap = [];
  $pieces = array_values((array)($cfg['pieces'] ?? []));
  $choices = array_values((array)($q['choices'] ?? []));
  foreach ($pieces as $idx => $piece) {
    $slot = (int)($piece['target_slot'] ?? 0);
    $choiceId = (int)($choices[$idx]['id'] ?? 0);
    if ($slot > 0 && $choiceId > 0) $correctMap['slot_' . $slot] = $choiceId;
  }
  $slotCount = max(1, count((array)($cfg['targets'] ?? [])));
  $chosenMap = quiz_drag_answer_map($answer);
  if (!$chosenMap || count($chosenMap) !== $slotCount || count($correctMap) !== $slotCount) return false;
  ksort($correctMap); ksort($chosenMap);
  return $correctMap === $chosenMap;
}

function quiz_get_questions(PDO $pdo, string $exam_id): array {
  quiz_safe_ensure_schema($pdo);

  $stmt = $pdo->prepare("SELECT * FROM quiz_questions WHERE exam_id=? AND is_active=1 ORDER BY sort_order ASC, id ASC");
  $stmt->execute([$exam_id]);
  $qs = $stmt->fetchAll() ?: [];
  if (!$qs) return [];
  $ids = array_map(fn($r) => (int)$r['id'], $qs);
  $in = implode(',', array_fill(0, count($ids), '?'));
  $stmt2 = $pdo->prepare("SELECT * FROM quiz_choices WHERE question_id IN ($in) ORDER BY sort_order ASC, id ASC");
  $stmt2->execute($ids);
  $choices = $stmt2->fetchAll() ?: [];
  $byQ = [];
  foreach ($choices as $c) {
    $qid = (int)$c['question_id'];
    if (!isset($byQ[$qid])) $byQ[$qid] = [];
    $byQ[$qid][] = $c;
  }
  foreach ($qs as &$q) {
    $qid = (int)$q['id'];
    $q['choices'] = $byQ[$qid] ?? [];
  }
  return $qs;
}
