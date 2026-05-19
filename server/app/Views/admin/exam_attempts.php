<?php
/** @var callable $buildQuery */
?>
<div class="admin-page-header">
  <div>
    <h1 class="admin-page-title">Attempts &amp; Scores</h1>
    <div class="admin-page-subtitle">Track who attempted each dumps, when they finished, and what they scored.</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="/admin/exams.php"><i class="bi bi-journal-text me-1"></i> Exams</a>
    <a class="btn btn-outline-secondary" href="/admin/"><i class="bi bi-arrow-left me-1"></i> Back</a>
  </div>
</div>

<div class="admin-panel mb-3">
  <div class="admin-panel-body">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-lg-3 col-md-6"><label class="form-label">Search User / Code</label><input class="form-control" name="q" value="<?= h($q) ?>" placeholder="User, username, access code, exam ID, IP, country, or city"></div>
      <div class="col-lg-2 col-md-6"><label class="form-label">Exam</label><select class="form-select" name="exam_id"><option value="">All exams</option><?php foreach ($exams as $exam): ?><option value="<?= h($exam['exam_id']) ?>" <?= ((string)$examFilter === (string)$exam['exam_id'])?'selected':'' ?>><?= h($exam['exam_name']) ?><?php if (!empty($exam['exam_id'])): ?> (<?= h($exam['exam_id']) ?>)<?php endif; ?></option><?php endforeach; ?></select></div>
      <div class="col-lg-2 col-md-4"><label class="form-label">Status</label><select class="form-select" name="status"><option value="">All</option><option value="finished" <?= $statusFilter==='finished'?'selected':'' ?>>Finished</option><option value="pending" <?= $statusFilter==='pending'?'selected':'' ?>>Pending</option></select></div>
      <div class="col-lg-2 col-md-4"><label class="form-label">Mode</label><select class="form-select" name="mode"><option value="">All modes</option><?php foreach ($modeOptions as $modeValue => $modeLabel): ?><option value="<?= h($modeValue) ?>" <?= $modeFilter===$modeValue?'selected':'' ?>><?= h($modeLabel) ?></option><?php endforeach; ?></select></div>
      <div class="col-lg-2 col-md-4"><label class="form-label">Date range</label><select class="form-select" name="range" onchange="const wrap=document.getElementById('attempts-custom-range'); if(wrap){wrap.classList.toggle('d-none', this.value!=='custom');}"><option value="">Select range</option><?php foreach ($rangeOptions as $value => $label): ?><option value="<?= h($value) ?>" <?= $datePreset===$value?'selected':'' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
      <div class="col-lg-1 col-md-4"><label class="form-label">Per page</label><select class="form-select" name="per_page"><?php foreach ($allowedPerPage as $size): ?><option value="<?= $size ?>" <?= $perPage===$size?'selected':'' ?>><?= $size ?></option><?php endforeach; ?></select></div>
      <div class="col-lg-2 col-md-12 d-grid"><button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i> Apply Filters</button></div>

      <div id="attempts-custom-range" class="row g-2 <?= $datePreset === 'custom' ? '' : 'd-none' ?> mt-1">
        <div class="col-md-3"><label class="form-label">From date</label><input class="form-control" type="date" name="from" value="<?= h($fromDate) ?>"></div>
        <div class="col-md-3"><label class="form-label">To date</label><input class="form-control" type="date" name="to" value="<?= h($toDate) ?>"></div>
        <div class="col-md-6 d-flex align-items-end"><div class="small text-muted">Custom range filters attempts by finished date, or started date if not finished yet.</div></div>
      </div>
    </form>
    <div class="d-flex flex-wrap gap-2 mt-3">
      <?php foreach (['today','last7','last15','last30','this_month','past_month','last3months','all'] as $quick): ?>
        <a class="btn btn-sm <?= $datePreset === $quick ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= h($buildQuery(['range' => $quick, 'page' => 1, 'from' => null, 'to' => null])) ?>"><?= h($rangeOptions[$quick]) ?></a>
      <?php endforeach; ?>
      <a class="btn btn-sm btn-outline-danger" href="/admin/exam_attempts.php">Reset</a>
    </div>
  </div>
</div>

<div class="admin-panel overflow-hidden">
  <div class="admin-panel-header">
    <div>
      <div class="admin-section-title">Attempt History</div>
      <div class="admin-section-subtitle">Showing <?= (int)$startRow ?>–<?= (int)$endRow ?> of <?= (int)$totalRows ?> records<?= $fromDate || $toDate ? ' for selected date range' : '' ?>.</div>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <span class="wp-pill wp-pill-blue"><i class="bi bi-list-check"></i> <?= (int)$totalRows ?> total</span>
      <span class="wp-pill wp-pill-gray"><i class="bi bi-collection"></i> Page <?= (int)$page ?> / <?= (int)$totalPages ?></span>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>#</th><th>User</th><th>Exam</th><th>Mode</th><th>Access Code</th><th>Location / IP</th><th>Progress</th><th>Time</th><th>Activity</th><th>Score</th><th>Status</th><th>Review</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="12" class="text-center text-muted py-4">No attempts found for the selected filters.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <?php
          $displayUser = trim((string)($r['linked_full_name'] ?? ''));
          if ($displayUser === '') $displayUser = trim((string)($r['linked_username'] ?? ''));
          if ($displayUser === '') $displayUser = trim((string)($r['user_name'] ?? ''));
          $pct = isset($r['percentage']) && $r['percentage'] !== null ? (float)$r['percentage'] : null;
          $attemptedCount = (int)($r['attempted_questions_count'] ?? 0);
          $flaggedCount = (int)($r['flagged_questions_count'] ?? 0);
          $resumeCount = (int)($r['resume_count'] ?? 0);
          $locationParts = [];
          if (!empty($r['city'])) $locationParts[] = (string)$r['city'];
          if (!empty($r['country'])) $locationParts[] = (string)$r['country'];
          $locationText = $locationParts ? implode(', ', $locationParts) : '—';
          $reviewUrl = $reviewBaseUrl . '/review?attempt_id=' . urlencode((string)$r['id']);
          $isFinished = !empty($r['finished_at']);
          $resultStatusText = strtolower(trim((string)($r['result_status'] ?? '')));
          if (!$isFinished) {
            $statusClass = 'wp-pill-gray';
            $statusIcon = 'hourglass-split';
            $statusText = 'Pending';
          } elseif ($resultStatusText === 'passed') {
            $statusClass = 'wp-pill-green';
            $statusIcon = 'patch-check';
            $statusText = 'Passed';
          } elseif ($resultStatusText === 'failed') {
            $statusClass = 'wp-pill-amber';
            $statusIcon = 'x-octagon';
            $statusText = 'Failed';
          } else {
            $statusClass = 'wp-pill-blue';
            $statusIcon = 'check2-circle';
            $statusText = 'Finished';
          }
        ?>
        <tr>
          <td class="mono"><?= (int)$r['id'] ?></td>
          <td>
            <div class="fw-semibold"><?= h($displayUser !== '' ? $displayUser : '—') ?></div>
            <?php if (!empty($r['linked_username']) && $displayUser !== $r['linked_username']): ?><div class="small text-muted mono"><?= h($r['linked_username']) ?></div><?php endif; ?>
          </td>
          <td>
            <div class="fw-semibold"><?= h($r['exam_title'] ?: $r['exam_id'] ?: '—') ?></div>
            <?php if (!empty($r['exam_id']) && (string)$r['exam_title'] !== (string)$r['exam_id']): ?><div class="small text-muted mono"><?= h($r['exam_id']) ?></div><?php endif; ?>
          </td>
          <td><span class="wp-pill wp-pill-blue text-uppercase"><?= h(quiz_mode_label((string)($r['exam_mode'] ?? 'exam'))) ?></span></td>
          <td class="mono"><?= h($r['code_plain'] ?: '—') ?></td>
          <td>
            <div class="small"><?= h($locationText) ?></div>
            <div class="small text-muted mono"><?= h($r['ip_address'] ?: '—') ?></div>
          </td>
          <td>
            <div class="small"><strong>Attempted:</strong> <?= $attemptedCount ?></div>
            <div class="small text-muted">Flagged: <?= $flaggedCount ?> • Resumes: <?= $resumeCount ?></div>
            <div class="small text-muted"><?= !empty($r['ended_via_button']) ? 'Ended using button' : ($isFinished ? 'Submitted normally' : 'In progress') ?></div>
          </td>
          <td>
            <div class="small"><strong>Total:</strong> <?= h($r['total_time_text'] ?? '—') ?></div>
            <div class="small text-muted">Started: <?= h($r['started_at'] ?: '—') ?></div>
            <div class="small text-muted">Finished: <?= h($r['finished_at'] ?: '—') ?></div>
          </td>
          <td>
            <div class="small"><strong>First opened:</strong> <?= h($r['first_question_opened_at'] ?: $r['started_at'] ?: '—') ?></div>
            <div class="small text-muted">Q#: <?= (int)($r['first_question_index'] ?? 1) ?></div>
            <div class="small text-muted">Last activity: <?= h($r['last_activity_at'] ?: '—') ?></div>
          </td>
          <td>
            <div class="fw-semibold"><?= (float)$r['score'] ?> / <?= (float)$r['max_score'] ?></div>
            <div class="small text-muted"><?= $pct !== null ? h(number_format($pct, 2) . '%') : '—' ?></div>
          </td>
          <td><span class="wp-pill <?= h($statusClass) ?>"><i class="bi bi-<?= h($statusIcon) ?>"></i> <?= h($statusText) ?></span></td>
          <td>
            <?php if ($isFinished): ?>
              <a class="btn btn-sm btn-outline-primary" href="<?= h($reviewUrl) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Review</a>
            <?php else: ?>
              <span class="text-muted small">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>      </tbody>
    </table>
  </div>
  <?php if ($totalPages > 1): ?>
    <div class="admin-panel-body border-top">
      <nav aria-label="Attempts pagination">
        <ul class="pagination flex-wrap mb-0">
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= h($buildQuery(['page' => max(1, $page - 1)])) ?>">Previous</a></li>
          <?php
            $windowStart = max(1, $page - 2);
            $windowEnd = min($totalPages, $page + 2);
            if ($windowStart > 1):
          ?>
            <li class="page-item"><a class="page-link" href="<?= h($buildQuery(['page' => 1])) ?>">1</a></li>
            <?php if ($windowStart > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
          <?php endif; ?>
          <?php for ($i = $windowStart; $i <= $windowEnd; $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= h($buildQuery(['page' => $i])) ?>"><?= $i ?></a></li>
          <?php endfor; ?>
          <?php if ($windowEnd < $totalPages): ?>
            <?php if ($windowEnd < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
            <li class="page-item"><a class="page-link" href="<?= h($buildQuery(['page' => $totalPages])) ?>"><?= $totalPages ?></a></li>
          <?php endif; ?>
          <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= h($buildQuery(['page' => min($totalPages, $page + 1)])) ?>">Next</a></li>
        </ul>
      </nav>
    </div>
  <?php endif; ?>
</div>
