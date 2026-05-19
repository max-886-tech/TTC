<?php
declare(strict_types=1);

namespace App\Services;

final class ReportingService {
  public static function formatDuration(mixed $seconds): string {
    if ($seconds === null || $seconds === '' || !is_numeric($seconds)) return '—';
    $seconds = max(0, (int)$seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;
    if ($hours > 0) return sprintf('%dh %02dm %02ds', $hours, $minutes, $secs);
    if ($minutes > 0) return sprintf('%dm %02ds', $minutes, $secs);
    return sprintf('%ds', $secs);
  }

  /** @return array<string,mixed> */
  public static function normalizeAttemptRow(array $row, string $reviewBaseUrl): array {
    $displayUser = trim((string)($row['linked_full_name'] ?? ''));
    if ($displayUser === '') $displayUser = trim((string)($row['linked_username'] ?? ''));
    if ($displayUser === '') $displayUser = trim((string)($row['user_name'] ?? ''));

    $pct = isset($row['percentage']) && $row['percentage'] !== null ? (float)$row['percentage'] : null;
    $attemptedCount = (int)($row['attempted_questions_count'] ?? 0);
    $flaggedCount = (int)($row['flagged_questions_count'] ?? 0);
    $resumeCount = (int)($row['resume_count'] ?? 0);
    $locationParts = [];
    if (!empty($row['city'])) $locationParts[] = (string)$row['city'];
    if (!empty($row['country'])) $locationParts[] = (string)$row['country'];
    $locationText = $locationParts ? implode(', ', $locationParts) : '—';
    $reviewUrl = $reviewBaseUrl . '/review?attempt_id=' . urlencode((string)($row['id'] ?? ''));
    $isFinished = !empty($row['finished_at']);
    $resultStatusText = strtolower(trim((string)($row['result_status'] ?? '')));

    if (!$isFinished) {
      $statusClass = 'wp-pill-gray'; $statusIcon = 'hourglass-split'; $statusText = 'Pending';
    } elseif ($resultStatusText === 'passed') {
      $statusClass = 'wp-pill-green'; $statusIcon = 'patch-check'; $statusText = 'Passed';
    } elseif ($resultStatusText === 'failed') {
      $statusClass = 'wp-pill-amber'; $statusIcon = 'x-octagon'; $statusText = 'Failed';
    } else {
      $statusClass = 'wp-pill-blue'; $statusIcon = 'check2-circle'; $statusText = 'Finished';
    }

    return $row + [
      'display_user' => $displayUser !== '' ? $displayUser : '—',
      'percentage_value' => $pct,
      'attempted_count' => $attemptedCount,
      'flagged_count' => $flaggedCount,
      'resume_count_value' => $resumeCount,
      'location_text' => $locationText,
      'review_url' => $reviewUrl,
      'is_finished' => $isFinished,
      'status_class' => $statusClass,
      'status_icon' => $statusIcon,
      'status_text' => $statusText,
      'total_time_text' => self::formatDuration($row['total_time_seconds'] ?? null),
    ];
  }
}
