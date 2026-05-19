<?php
function quiz_bool_flag(array $row, string $key, int $default = 0): int {
  return isset($row[$key]) ? (int)!empty($row[$key]) : $default;
}


function quiz_exam_ui_settings(array $exam): array {
  return quiz_build_effective_ui_settings($exam, 'exam');
}


function quiz_default_mode_settings(): array {
  return [
    'practice' => [
      'enabled' => 1,
      'question_limit' => 'all',
      'question_count' => 0,
      'show_timer' => 0,
      'show_explanation' => 1,
      'instant_feedback' => 1,
      'allow_previous' => 1,
      'allow_review' => 1,
      'show_result' => 1,
      'shuffle_questions' => 1,
      'shuffle_choices' => 1,
      'hide_points' => 0,
      'hide_question_type' => 0,
      'hide_flag_button' => 0,
    ],
    'exam' => [
      'enabled' => 1,
      'question_limit' => 'all',
      'question_count' => 0,
      'show_timer' => 1,
      'show_explanation' => 0,
      'instant_feedback' => 0,
      'allow_previous' => 0,
      'allow_review' => 1,
      'show_result' => 1,
      'shuffle_questions' => 1,
      'shuffle_choices' => 1,
      'hide_points' => 1,
      'hide_question_type' => 1,
      'hide_flag_button' => 0,
    ],
    'demo' => [
      'enabled' => 0,
      'question_limit' => 'custom',
      'question_count' => 10,
      'show_timer' => 0,
      'show_explanation' => 1,
      'instant_feedback' => 1,
      'allow_previous' => 1,
      'allow_review' => 1,
      'show_result' => 1,
      'shuffle_questions' => 1,
      'shuffle_choices' => 1,
      'hide_points' => 0,
      'hide_question_type' => 0,
      'hide_flag_button' => 0,
    ],
  ];
}


function quiz_normalize_mode_name(?string $mode): string {
  $mode = strtolower(trim((string)$mode));
  return in_array($mode, ['practice', 'exam', 'demo'], true) ? $mode : 'exam';
}


function quiz_mode_label(string $mode): string {
  $mode = quiz_normalize_mode_name($mode);
  return $mode === 'practice' ? 'Practice Mode' : ($mode === 'demo' ? 'Demo Exam' : 'Exam Mode');
}


function quiz_normalize_mode_config(array $cfg, array $fallback = []): array {
  $base = array_merge([
    'enabled' => 0,
    'question_limit' => 'all',
    'question_count' => 0,
    'show_timer' => 1,
    'show_explanation' => 0,
    'instant_feedback' => 0,
    'allow_previous' => 1,
    'allow_review' => 1,
    'show_result' => 1,
    'shuffle_questions' => 1,
    'shuffle_choices' => 1,
    'hide_points' => 0,
    'hide_question_type' => 0,
    'hide_flag_button' => 0,
  ], $fallback, $cfg);

  $base['enabled'] = !empty($base['enabled']) ? 1 : 0;
  $base['question_limit'] = strtolower(trim((string)($base['question_limit'] ?? 'all')));
  if (!in_array($base['question_limit'], ['all', 'custom'], true)) $base['question_limit'] = 'all';
  $base['question_count'] = max(0, (int)($base['question_count'] ?? 0));
  foreach (['show_timer','show_explanation','instant_feedback','allow_previous','allow_review','show_result','shuffle_questions','shuffle_choices','hide_points','hide_question_type','hide_flag_button'] as $k) {
    $base[$k] = !empty($base[$k]) ? 1 : 0;
  }
  return $base;
}


function quiz_exam_mode_settings(array $exam): array {
  $defaults = quiz_default_mode_settings();
  $saved = quiz_decode_json_array((string)($exam['mode_settings_json'] ?? ''), []);
  $out = [];
  foreach ($defaults as $mode => $cfg) {
    $out[$mode] = quiz_normalize_mode_config((array)($saved[$mode] ?? []), $cfg);
  }
  return $out;
}


function quiz_default_access_code_mode_settings(): array {
  return [
    'practice' => ['enabled' => 1],
    'exam' => ['enabled' => 1],
    'demo' => ['enabled' => 1],
  ];
}


function quiz_normalize_access_code_mode_settings(array $cfg, array $fallback = []): array {
  $base = array_merge(['enabled' => 0], $fallback, $cfg);
  $base['enabled'] = !empty($base['enabled']) ? 1 : 0;
  return $base;
}


function quiz_access_code_mode_settings(array $codeRow): array {
  $defaults = quiz_default_access_code_mode_settings();
  $saved = quiz_decode_json_array((string)($codeRow['mode_access_json'] ?? ''), []);
  $out = [];
  foreach ($defaults as $mode => $cfg) {
    $out[$mode] = quiz_normalize_access_code_mode_settings((array)($saved[$mode] ?? []), $cfg);
  }
  return $out;
}


function quiz_access_code_available_modes(array $exam, array $codeRow): array {
  $examModes = quiz_exam_mode_settings($exam);
  $codeModes = quiz_access_code_mode_settings($codeRow);
  $out = [];
  foreach (['practice', 'exam', 'demo'] as $mode) {
    if (!empty($examModes[$mode]['enabled']) && !empty($codeModes[$mode]['enabled'])) {
      $out[] = $mode;
    }
  }
  return $out;
}


function quiz_get_mode_config(array $exam, string $mode): array {
  $mode = quiz_normalize_mode_name($mode);
  $all = quiz_exam_mode_settings($exam);
  return $all[$mode] ?? quiz_normalize_mode_config([], quiz_default_mode_settings()['exam']);
}


function quiz_exam_available_modes(array $exam): array {
  $all = quiz_exam_mode_settings($exam);
  $out = [];
  foreach ($all as $mode => $cfg) {
    if (!empty($cfg['enabled'])) $out[] = $mode;
  }
  if (!$out) $out[] = 'exam';
  return $out;
}


function quiz_build_effective_ui_settings(array $exam, string $mode): array {
  $cfg = quiz_get_mode_config($exam, $mode);
  return [
    'disable_previous' => !empty($cfg['allow_previous']) ? 0 : 1,
    'hide_points' => !empty($cfg['hide_points']) ? 1 : quiz_bool_flag($exam, 'ui_hide_points'),
    'hide_question_type' => !empty($cfg['hide_question_type']) ? 1 : quiz_bool_flag($exam, 'ui_hide_question_type'),
    'hide_explanation' => !empty($cfg['show_explanation']) ? 0 : 1,
    'hide_flag_button' => !empty($cfg['hide_flag_button']) ? 1 : quiz_bool_flag($exam, 'ui_hide_flag_button'),
    'instant_feedback' => !empty($cfg['instant_feedback']) ? 1 : 0,
    'show_timer' => !empty($cfg['show_timer']) ? 1 : 0,
    'allow_review' => !empty($cfg['allow_review']) ? 1 : 0,
    'show_result' => !empty($cfg['show_result']) ? 1 : 0,
  ];
}


function quiz_limit_questions_for_mode(array $questions, array $modeCfg): array {
  $limitType = (string)($modeCfg['question_limit'] ?? 'all');
  $limitCount = (int)($modeCfg['question_count'] ?? 0);
  if ($limitType === 'custom' && $limitCount > 0 && count($questions) > $limitCount) {
    return array_slice($questions, 0, $limitCount);
  }
  return $questions;
}



