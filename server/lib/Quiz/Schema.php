<?php
function quiz_ensure_schema(PDO $pdo): void {
  // Phase 4+: Prefer migrations over runtime DDL.
  if (defined('APP_MIGRATIONS_RAN') && APP_MIGRATIONS_RAN) {
    return;
  }

  // exams
  $pdo->exec("CREATE TABLE IF NOT EXISTS exams (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    exam_id VARCHAR(64) NOT NULL,
    exam_name VARCHAR(255) NOT NULL,
    resource_type ENUM('enbl','quiz') NOT NULL DEFAULT 'enbl',
    r2_key VARCHAR(512) NULL,
    r2_url VARCHAR(1024) NULL,
    duration_minutes INT UNSIGNED NULL,
    shuffle_questions TINYINT(1) NOT NULL DEFAULT 1,
    shuffle_choices TINYINT(1) NOT NULL DEFAULT 1,
    terms_text TEXT NULL,
    ui_disable_previous TINYINT(1) NOT NULL DEFAULT 0,
    ui_hide_points TINYINT(1) NOT NULL DEFAULT 0,
    ui_hide_question_type TINYINT(1) NOT NULL DEFAULT 0,
    ui_hide_explanation TINYINT(1) NOT NULL DEFAULT 0,
    ui_hide_flag_button TINYINT(1) NOT NULL DEFAULT 0,
    mode_settings_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_exams_exam_id (exam_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  // quiz tables
  $pdo->exec("CREATE TABLE IF NOT EXISTS quiz_questions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    exam_id VARCHAR(64) NOT NULL,
    question_text TEXT NOT NULL,
    question_image VARCHAR(1024) NULL,
    explanation_text TEXT NULL,
    question_type ENUM('single','multiple','yes_no','dropdown_matrix','drag_drop') NOT NULL DEFAULT 'single',
    drag_drop_mode ENUM('categorize') NULL DEFAULT NULL,
    drag_drop_config_json LONGTEXT NULL,
    points INT UNSIGNED NOT NULL DEFAULT 1,
    sort_order INT UNSIGNED NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_questions_exam (exam_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  // Backward-compatible schema upgrades (older installs won't have new columns)
  try {
    $pdo->exec("ALTER TABLE quiz_questions ADD COLUMN question_image VARCHAR(1024) NULL AFTER question_text");
  } catch (Throwable $e) {
    // ignore (column already exists / insufficient privileges)
  }

  try {
    $pdo->exec("ALTER TABLE quiz_questions ADD COLUMN explanation_text TEXT NULL AFTER question_image");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_questions ADD COLUMN case_study_text MEDIUMTEXT NULL AFTER explanation_text");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_questions ADD COLUMN drag_drop_mode ENUM('categorize') NULL DEFAULT NULL AFTER question_type");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_questions ADD COLUMN drag_drop_config_json LONGTEXT NULL AFTER drag_drop_mode");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_questions MODIFY COLUMN question_type ENUM('single','multiple','yes_no','dropdown_matrix','drag_drop') NOT NULL DEFAULT 'single'");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_choices ADD COLUMN choice_image VARCHAR(1024) NULL AFTER choice_text");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_choices ADD COLUMN drag_target_index INT UNSIGNED NULL AFTER is_correct");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_choices ADD COLUMN drag_blank_key LONGTEXT NULL AFTER drag_target_index");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_choices MODIFY COLUMN drag_blank_key LONGTEXT NULL");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_choices ADD COLUMN is_distractor TINYINT(1) NOT NULL DEFAULT 0 AFTER drag_blank_key");
  } catch (Throwable $e) {
    // ignore
  }


  try {
    $pdo->exec("ALTER TABLE exams ADD COLUMN terms_text TEXT NULL AFTER shuffle_choices");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE exams ADD COLUMN ui_hide_flag_button TINYINT(1) NOT NULL DEFAULT 0 AFTER ui_hide_explanation");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE exams ADD COLUMN ui_hide_explanation TINYINT(1) NOT NULL DEFAULT 0 AFTER ui_hide_question_type");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE exams ADD COLUMN ui_hide_question_type TINYINT(1) NOT NULL DEFAULT 0 AFTER ui_hide_points");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE exams ADD COLUMN ui_hide_points TINYINT(1) NOT NULL DEFAULT 0 AFTER ui_disable_previous");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE exams ADD COLUMN ui_disable_previous TINYINT(1) NOT NULL DEFAULT 0 AFTER terms_text");
  } catch (Throwable $e) {
    // ignore
  }
  try {
    $pdo->exec("ALTER TABLE exams ADD COLUMN mode_settings_json LONGTEXT NULL AFTER ui_hide_flag_button");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_attempts ADD COLUMN user_id INT UNSIGNED NULL AFTER access_code_id");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_attempts ADD COLUMN percentage DECIMAL(7,2) NULL AFTER max_score");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_attempts ADD COLUMN result_status VARCHAR(20) NULL AFTER percentage");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_attempts ADD COLUMN ip_address VARCHAR(64) NULL AFTER device_hash");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_attempts ADD COLUMN user_agent VARCHAR(512) NULL AFTER ip_address");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_attempts ADD COLUMN exam_mode VARCHAR(20) NOT NULL DEFAULT 'exam' AFTER state_json");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_attempts ADD COLUMN mode_config_json LONGTEXT NULL AFTER exam_mode");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_attempts ADD COLUMN country VARCHAR(100) NULL AFTER user_agent");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_attempts ADD COLUMN city VARCHAR(100) NULL AFTER country");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_attempts ADD COLUMN attempted_questions_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER mode_config_json");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_attempts ADD COLUMN flagged_questions_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER attempted_questions_count");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_attempts ADD COLUMN ended_via_button TINYINT(1) NOT NULL DEFAULT 0 AFTER flagged_questions_count");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_attempts ADD COLUMN total_time_seconds INT UNSIGNED NULL AFTER ended_via_button");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_attempts ADD COLUMN first_question_opened_at DATETIME NULL AFTER total_time_seconds");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_attempts ADD COLUMN first_question_index INT UNSIGNED NULL AFTER first_question_opened_at");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_attempts ADD COLUMN last_activity_at DATETIME NULL AFTER first_question_index");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_attempts ADD COLUMN resume_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_activity_at");
  } catch (Throwable $e) {
    // ignore
  }


  try {
    $pdo->exec("ALTER TABLE quiz_attempts ADD COLUMN payload_json LONGTEXT NULL AFTER result_status");
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $pdo->exec("ALTER TABLE quiz_attempts ADD COLUMN state_json LONGTEXT NULL AFTER payload_json");
  } catch (Throwable $e) {
    // ignore
  }

  $pdo->exec("CREATE TABLE IF NOT EXISTS quiz_choices (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    question_id INT UNSIGNED NOT NULL,
    choice_text TEXT NOT NULL,
    choice_image VARCHAR(1024) NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    drag_target_index INT UNSIGNED NULL,
    drag_blank_key LONGTEXT NULL,
    is_distractor TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_choice_q (question_id),
    CONSTRAINT fk_choices_question
      FOREIGN KEY (question_id) REFERENCES quiz_questions(id)
      ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  $pdo->exec("CREATE TABLE IF NOT EXISTS quiz_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    exam_id VARCHAR(64) NOT NULL,
    access_code_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    user_name VARCHAR(255) NULL,
    device_hash VARCHAR(255) NULL,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(512) NULL,
    country VARCHAR(100) NULL,
    city VARCHAR(100) NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    score INT UNSIGNED NULL,
    max_score INT UNSIGNED NULL,
    percentage DECIMAL(7,2) NULL,
    result_status VARCHAR(20) NULL,
    payload_json LONGTEXT NULL,
    state_json LONGTEXT NULL,
    exam_mode VARCHAR(20) NOT NULL DEFAULT 'exam',
    mode_config_json LONGTEXT NULL,
    attempted_questions_count INT UNSIGNED NOT NULL DEFAULT 0,
    flagged_questions_count INT UNSIGNED NOT NULL DEFAULT 0,
    ended_via_button TINYINT(1) NOT NULL DEFAULT 0,
    total_time_seconds INT UNSIGNED NULL,
    first_question_opened_at DATETIME NULL,
    first_question_index INT UNSIGNED NULL,
    last_activity_at DATETIME NULL,
    resume_count INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_attempts_exam (exam_id),
    KEY idx_attempts_mode (exam_mode),
    KEY idx_attempts_ip (ip_address)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  $pdo->exec("CREATE TABLE IF NOT EXISTS quiz_answers (
    attempt_id BIGINT UNSIGNED NOT NULL,
    question_id INT UNSIGNED NOT NULL,
    choice_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (attempt_id, question_id, choice_id),
    KEY idx_answers_question (question_id),
    CONSTRAINT fk_ans_attempt
      FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id)
      ON DELETE CASCADE,
    CONSTRAINT fk_ans_question
      FOREIGN KEY (question_id) REFERENCES quiz_questions(id)
      ON DELETE CASCADE,
    CONSTRAINT fk_ans_choice
      FOREIGN KEY (choice_id) REFERENCES quiz_choices(id)
      ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}


function quiz_safe_ensure_schema(PDO $pdo): void {
  if (!$pdo->inTransaction()) {
    quiz_ensure_schema($pdo);
  }
}


