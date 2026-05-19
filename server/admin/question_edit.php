<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/quiz.php';

require_permission('quiz.manage');

function qe_parse_blank_keys(string $raw): array {
  $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];
  $out = [];
  foreach ($parts as $p) {
    $p = trim((string)$p);
    if ($p !== '') $out[] = $p;
  }
  return array_values(array_unique($out));
}

function qe_drag_config_from_post(array $src): array {
  $rawMode = (string)($src['drag_drop_mode'] ?? 'categorize');
  $mode = quiz_normalize_drag_drop_mode($rawMode === 'categorize_image' ? 'categorize' : $rawMode);
  if ($mode === 'categorize') {
    $visualMode = 'image_slices';
    if (!in_array($visualMode, ['text_boxes', 'image_slices'], true)) $visualMode = 'text_boxes';
    $pieces = qe_parse_rect_list((string)($src['drag_image_pieces_json'] ?? '[]'));
    $targets = qe_parse_rect_list((string)($src['drag_image_targets_json'] ?? '[]'));
    $slotCount = max(1, (int)($src['drag_slot_count'] ?? 1));
    if ($visualMode === 'image_slices') {
      $slotCount = max(1, count($targets));
    }
    return [
      'mode' => $mode,
      'visual_mode' => $visualMode,
      'slot_count' => $slotCount,
      'exact_slot_match' => isset($src['drag_exact_slot_match']) ? 1 : 0,
      'image_url' => trim((string)($src['drag_image_url'] ?? '')),
      'image_width' => max(0, (int)($src['drag_image_width'] ?? 0)),
      'image_height' => max(0, (int)($src['drag_image_height'] ?? 0)),
      'pieces' => $pieces,
      'targets' => $targets,
    ];
  }
  if ($mode === 'blanks') {
    return [
      'mode' => $mode,
      'blank_keys' => qe_parse_blank_keys((string)($src['drag_blank_keys'] ?? '')),
    ];
  }
  return ['mode' => 'categorize', 'visual_mode' => 'image_slices', 'pieces' => [], 'targets' => [], 'image_url' => ''];
}


function qe_parse_rect_list($json): array {
  $data = json_decode((string)$json, true);
  if (!is_array($data)) return [];
  $out = [];
  foreach ($data as $row) {
    if (!is_array($row)) continue;
    $out[] = [
      'x' => max(0, (float)($row['x'] ?? 0)),
      'y' => max(0, (float)($row['y'] ?? 0)),
      'w' => max(1, (float)($row['w'] ?? 1)),
      'h' => max(1, (float)($row['h'] ?? 1)),
      'label' => trim((string)($row['label'] ?? '')),
      'target_slot' => max(0, (int)($row['target_slot'] ?? 0)),
    ];
  }
  return $out;
}



function qe_matrix_options_from_storage($value): array {
  if (is_array($value)) {
    return array_values(array_filter(array_map('trim', $value), fn($v) => $v !== ''));
  }
  $raw = trim((string)$value);
  if ($raw === '') return [];
  $decoded = json_decode($raw, true);
  if (is_array($decoded)) {
    return array_values(array_filter(array_map('trim', $decoded), fn($v) => $v !== ''));
  }
  $lines = preg_split('/
|
|
/', $raw) ?: [];
  return array_values(array_filter(array_map('trim', $lines), fn($v) => $v !== ''));
}

function qe_build_choices(array $src): array {
  $questionType = (string)($src['question_type'] ?? 'single');
  $rawDragMode = (string)($src['drag_drop_mode'] ?? 'categorize');
  $drag_mode = quiz_normalize_drag_drop_mode($rawDragMode === 'categorize_image' ? 'categorize' : $rawDragMode);
  $visualMode = (string)($src['drag_visual_mode'] ?? '');
  if ($questionType === 'drag_drop' && $drag_mode === 'categorize' && $visualMode === 'image_slices') {
    $pieces = qe_parse_rect_list((string)($src['drag_image_pieces_json'] ?? '[]'));
    $choices = [];
    foreach ($pieces as $i => $piece) {
      $choices[] = [
        'id' => 0,
        'text' => trim((string)($piece['label'] ?? ('Piece ' . ($i + 1)))) ?: ('Piece ' . ($i + 1)),
        'correct' => 0,
        'target' => max(0, (int)($piece['target_slot'] ?? 0)),
        'blank_key' => '',
        'is_distractor' => ((int)($piece['target_slot'] ?? 0) <= 0 ? 1 : 0),
        'order' => (int)$i + 1,
      ];
    }
    return $choices;
  }

  $choice_texts = $src['choice_text'] ?? [];
  $choice_correct = $src['choice_correct'] ?? [];
  if ($questionType === 'yes_no' && isset($src['yes_no_answer']) && is_array($src['yes_no_answer'])) {
    $choice_correct = [];
    foreach ($src['yes_no_answer'] as $i => $ans) {
      if ((string)$ans === 'yes') $choice_correct[] = (string)$i;
    }
  }
  $matrix_options = $src['matrix_options'] ?? [];
  $matrix_correct = $src['matrix_correct'] ?? [];
  if (!is_array($choice_correct)) $choice_correct = [$choice_correct];
  $drag_targets = $src['drag_target_index'] ?? [];
  $drag_blank_keys = $src['drag_blank_key'] ?? [];
  $distractors = $src['choice_distractor'] ?? [];

  $choices = [];
  foreach ($choice_texts as $i => $t) {
    $t = trim((string)$t);
    if ($t === '') continue;
    $optionLines = preg_split('/
|
|
/', (string)($matrix_options[$i] ?? '')) ?: [];
    $optionList = [];
    foreach ($optionLines as $line) {
      $line = trim((string)$line);
      if ($line !== '') $optionList[] = $line;
    }
    $correctIndex = max(0, (int)($matrix_correct[$i] ?? 0));
    $choices[] = [
      'id' => (int)($src['choice_id'][$i] ?? 0),
      'text' => $t,
      'correct' => in_array((string)$i, (array)$choice_correct, true) ? 1 : 0,
      'target' => $questionType === 'dropdown_matrix' ? $correctIndex : max(0, (int)($drag_targets[$i] ?? 0)),
      'blank_key' => $questionType === 'dropdown_matrix' ? json_encode(array_values($optionList), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : trim((string)($drag_blank_keys[$i] ?? '')),
      'is_distractor' => in_array((string)$i, (array)$distractors, true) ? 1 : 0,
      'order' => (int)$i + 1,
    ];
  }
  return $choices;
}


function qe_validate(string $question_type, string $drag_mode, array $drag_config, array $choices): string {
  if (count($choices) < 2) return 'Add at least 2 choices / crop areas.';
  if ($question_type === 'single' || $question_type === 'yes_no') {
    $correctCount = 0;
    foreach ($choices as $c) if ($c['correct'] === 1) $correctCount++;
    return $correctCount === 1 ? '' : 'Single-choice must have exactly 1 correct option.';
  }
  if ($question_type === 'dropdown_matrix') {
    foreach ($choices as $c) {
      $opts = json_decode((string)($c['blank_key'] ?? ''), true);
      if (!is_array($opts)) $opts = [];
      $opts = array_values(array_filter(array_map('trim', $opts), fn($v) => $v !== ''));
      if (count($opts) < 2) return 'Each Drop-down Matrix statement must have at least 2 dropdown options.';
      $correctIndex = (int)($c['target'] ?? 0);
      if ($correctIndex < 1 || $correctIndex > count($opts)) return 'Each Drop-down Matrix statement must have one correct dropdown answer selected.';
    }
    return '';
  }
  if ($question_type === 'multiple') {
    $correctCount = 0;
    foreach ($choices as $c) if ($c['correct'] === 1) $correctCount++;
    return $correctCount >= 1 ? '' : 'Multiple-choice must have at least 1 correct option.';
  }
  if ($drag_mode === 'categorize') {
    $slotCount = max(1, (int)($drag_config['slot_count'] ?? 1));
    $visualMode = (string)($drag_config['visual_mode'] ?? 'text_boxes');
    if ($visualMode === 'image_slices') {
      if (trim((string)($drag_config['image_url'] ?? '')) === '') return 'Drag & drop image URL is required.';
      if (count((array)($drag_config['pieces'] ?? [])) < 2) return 'Add at least 2 draggable crop areas.';
      if (count((array)($drag_config['targets'] ?? [])) < 1) return 'Add at least 1 target area.';
      $slotCount = max(1, count((array)($drag_config['targets'] ?? [])));
    }
    $seen = [];
    foreach ($choices as $c) {
      if ($c['is_distractor']) continue;
      if ($c['target'] < 1 || $c['target'] > $slotCount) return 'Each non-distractor choice must be assigned to a valid target slot.';
      if (isset($seen[$c['target']])) return 'Each categorize target slot can have only one correct choice.';
      $seen[$c['target']] = true;
    }
    return count($seen) === $slotCount ? '' : 'Categorize mode requires exactly one correct choice for each target slot.';
  }
  return '';
}

$pdo = db();
quiz_safe_ensure_schema($pdo);

$id = (int)($_GET['id'] ?? 0);
$exam_id = trim($_GET['exam_id'] ?? '');

$stmt = $pdo->prepare("SELECT * FROM quiz_questions WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$q = $stmt->fetch();
if (!$q) {
  header("Location: /admin/exams.php");
  exit;
}
if ($exam_id === '') $exam_id = (string)$q['exam_id'];

$exam = quiz_get_exam($pdo, $exam_id);
if (!$exam || (string)$exam['resource_type'] !== 'quiz') {
  header("Location: /admin/exams.php");
  exit;
}

$stmtC = $pdo->prepare("SELECT * FROM quiz_choices WHERE question_id=? ORDER BY sort_order ASC, id ASC");
$stmtC->execute([$id]);
$choices = $stmtC->fetchAll() ?: [];

$stmtSO = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_so FROM quiz_questions WHERE exam_id=?');
$stmtSO->execute([$exam_id]);
$suggest_next_sort_order = (int)($stmtSO->fetchColumn() ?: 1);
if ($suggest_next_sort_order <= 0) $suggest_next_sort_order = 1;

$existingDragConfig = quiz_question_drag_config($q);
$q['drag_drop_config'] = $existingDragConfig;
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $question_text = trim($_POST['question_text'] ?? '');
  $explanation_text = trim($_POST['explanation_text'] ?? '');
  $case_study_text = trim($_POST['case_study_text'] ?? '');
  $question_type = trim($_POST['question_type'] ?? 'single');
  $drag_drop_mode = quiz_normalize_drag_drop_mode((string)($_POST['drag_drop_mode'] ?? 'categorize'));
  $drag_config = qe_drag_config_from_post($_POST);
  $points = (int)($_POST['points'] ?? 1);
  $sort_order = (int)($_POST['sort_order'] ?? 1);
  $is_active = isset($_POST['is_active']) ? 1 : 0;

  if ($question_text === '') {
    $msg = 'Question text is required.';
  } else {
    if (!in_array($question_type, ['single','multiple','yes_no','dropdown_matrix','drag_drop'], true)) $question_type = 'single';
    if ($points <= 0) $points = 1;
    if ($sort_order <= 0) $sort_order = 1;

    $newChoices = qe_build_choices($_POST);
    $msg = qe_validate($question_type, $drag_drop_mode, $drag_config, $newChoices);

    if ($msg === '') {
      try {
        $pdo->beginTransaction();

        $updQ = $pdo->prepare("UPDATE quiz_questions SET question_text=?, explanation_text=?, case_study_text=?, question_type=?, drag_drop_mode=?, drag_drop_config_json=?, points=?, sort_order=?, is_active=? WHERE id=?");
        $updQ->execute([
          $question_text,
          ($explanation_text !== '' ? $explanation_text : null),
          ($case_study_text !== '' ? $case_study_text : null),
          $question_type,
          $question_type === 'drag_drop' ? $drag_drop_mode : null,
          $question_type === 'drag_drop' ? json_encode($drag_config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
          $points,
          $sort_order,
          $is_active,
          $id,
        ]);

        $existingIds = array_map(fn($r) => (int)$r['id'], $choices);
        $keepIds = [];
        foreach ($newChoices as $c) if ((int)$c['id'] > 0) $keepIds[] = (int)$c['id'];

        $toDelete = array_diff($existingIds, $keepIds);
        if ($toDelete) {
          $del = $pdo->prepare("DELETE FROM quiz_choices WHERE id=? AND question_id=?");
          foreach ($toDelete as $cid) $del->execute([(int)$cid, $id]);
        }

        $updC = $pdo->prepare("UPDATE quiz_choices SET choice_text=?, is_correct=?, drag_target_index=?, drag_blank_key=?, is_distractor=?, sort_order=? WHERE id=? AND question_id=?");
        $insC = $pdo->prepare("INSERT INTO quiz_choices (question_id, choice_text, choice_image, is_correct, drag_target_index, drag_blank_key, is_distractor, sort_order) VALUES (?,?,?,?,?,?,?,?)");
        foreach ($newChoices as $c) {
          $cid = (int)$c['id'];
          $isDrag = $question_type === 'drag_drop';
          $isDropdownMatrix = $question_type === 'dropdown_matrix';
          $correct = ($isDrag || $isDropdownMatrix) ? 0 : $c['correct'];
          $target = $isDropdownMatrix ? max(0, (int)($c['target'] ?? 0)) : (($isDrag && $drag_drop_mode === 'categorize' && !$c['is_distractor']) ? $c['target'] : null);
          $blankKey = $isDropdownMatrix ? (string)($c['blank_key'] ?? '') : null;
          $isDistractor = $isDrag ? $c['is_distractor'] : 0;
          if ($cid > 0) {
            $updC->execute([$c['text'], $correct, $target, $blankKey, $isDistractor, $c['order'], $cid, $id]);
          } else {
            $insC->execute([$id, $c['text'], null, $correct, $target, $blankKey, $isDistractor, $c['order']]);
          }
        }

        quiz_invalidate_exam_attempts($pdo, $exam_id);
        $pdo->commit();
        header("Location: /admin/questions.php?exam_id=" . urlencode($exam_id));
        exit;
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = 'Update failed: ' . (APP_DEBUG ? $e->getMessage() : 'Server error.');
      }
    }
  }
}

$qt = $_POST['question_type'] ?? $q['question_type'];
$ddm = 'categorize_image';
$dragSlotCountValue = $_POST['drag_slot_count'] ?? (string)($existingDragConfig['slot_count'] ?? 3);
$dragBlankKeysValue = $_POST['drag_blank_keys'] ?? implode(' ', (array)($existingDragConfig['blank_keys'] ?? []));
?>
<?php
require_once __DIR__ . '/_header.php';

$pageTitle = 'Edit Question';
$submitLabel = 'Save Question';
$pointsValue = $_POST['points'] ?? $q['points'];
$sortOrderValue = $_POST['sort_order'] ?? $q['sort_order'];
$sortHelpHtml = 'Auto-filled from current value. Next available is <strong>' . (int)$suggest_next_sort_order . '</strong>. You can still change it or use the button.';
$showUseNextButton = true;
$useNextValue = (int)$suggest_next_sort_order;
$isActiveChecked = isset($_POST['is_active']) ? true : ((int)$q['is_active'] === 1);
$dragExactChecked = !isset($_POST['question_type']) ? !empty($existingDragConfig['exact_slot_match']) : !empty($_POST['drag_exact_slot_match']);
$dragVisualModeValue = $_POST['drag_visual_mode'] ?? (($q['drag_drop_config']['visual_mode'] ?? '') ?: 'text_boxes');
$dragImageUrlValue = $_POST['drag_image_url'] ?? (($q['drag_drop_config']['image_url'] ?? '') ?: '');
$dragImagePiecesJsonValue = $_POST['drag_image_pieces_json'] ?? json_encode(($q['drag_drop_config']['pieces'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$dragImageTargetsJsonValue = $_POST['drag_image_targets_json'] ?? json_encode(($q['drag_drop_config']['targets'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$dragImageWidthValue = $_POST['drag_image_width'] ?? (($q['drag_drop_config']['image_width'] ?? '') ?: '');
$dragImageHeightValue = $_POST['drag_image_height'] ?? (($q['drag_drop_config']['image_height'] ?? '') ?: '');
$questionTextValue = $_POST['question_text'] ?? $q['question_text'];
$explanationTextValue = $_POST['explanation_text'] ?? ($q['explanation_text'] ?? '');
$caseStudyTextValue = $_POST['case_study_text'] ?? ($q['case_study_text'] ?? '');
$initialRows = [];
$postChoices = $_POST['choice_text'] ?? null;
if (is_array($postChoices)) {
  foreach ($postChoices as $i => $t) {
    $initialRows[] = [
      'id' => (int)($_POST['choice_id'][$i] ?? 0),
      'text' => (string)$t,
      'correct' => in_array((string)$i, (array)($_POST['choice_correct'] ?? []), true),
      'target' => (int)($_POST['drag_target_index'][$i] ?? 0),
      'blank_key' => (string)($_POST['drag_blank_key'][$i] ?? ''),
      'matrix_options' => (string)($_POST['matrix_options'][$i] ?? ''),
      'matrix_correct' => (int)($_POST['matrix_correct'][$i] ?? 0),
      'is_distractor' => in_array((string)$i, (array)($_POST['choice_distractor'] ?? []), true),
    ];
  }
} else {
  foreach ($choices as $c) {
    $initialRows[] = [
      'id' => (int)$c['id'],
      'text' => (string)$c['choice_text'],
      'correct' => (int)$c['is_correct'] === 1,
      'target' => (int)($c['drag_target_index'] ?? 0),
      'blank_key' => (string)($c['drag_blank_key'] ?? ''),
      'matrix_options' => implode("\n", qe_matrix_options_from_storage($c['drag_blank_key'] ?? '')),
      'matrix_correct' => (int)($c['drag_target_index'] ?? 0),
      'is_distractor' => !empty($c['is_distractor']),
    ];
  }
  if (!$initialRows) {
    $initialRows = array_fill(0, 4, ['id' => 0, 'text' => '', 'correct' => false, 'target' => 0, 'blank_key' => '', 'matrix_options' => '', 'matrix_correct' => 0, 'is_distractor' => false]);
  }
}
require __DIR__ . '/_question_form.php';
