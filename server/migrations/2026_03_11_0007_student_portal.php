<?php
declare(strict_types=1);

return function(PDO $pdo): void {
  $hasTable = function(string $table) use ($pdo): bool {
    $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  };

  $hasCol = function(string $table, string $col) use ($pdo): bool {
    $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
  };

  if (!$hasTable('student_exam_assignments')) {
    $pdo->exec("
      CREATE TABLE student_exam_assignments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        exam_id VARCHAR(64) NOT NULL,
        available_from DATETIME NULL,
        available_until DATETIME NULL,
        max_attempts INT UNSIGNED NOT NULL DEFAULT 1,
        status VARCHAR(20) NOT NULL DEFAULT 'assigned',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_student_exam_assignments_user (user_id),
        KEY idx_student_exam_assignments_exam (exam_id),
        CONSTRAINT fk_student_exam_assignments_user FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
  }

  if (!$hasTable('student_notifications')) {
    $pdo->exec("
      CREATE TABLE student_notifications (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_student_notifications_user (user_id),
        CONSTRAINT fk_student_notifications_user FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
  }

  if ($hasTable('permissions')) {
    try {
      $stmt = $pdo->prepare("INSERT IGNORE INTO permissions (code, label) VALUES (?, ?)");
      $stmt->execute(['student.portal', 'Access student portal']);
    } catch (Throwable $e) {}
  }
};
