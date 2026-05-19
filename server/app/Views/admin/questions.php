<?php
  $page = (int)($page ?? 1);
  $perPage = (int)($perPage ?? 25);
  $totalPages = (int)($totalPages ?? 1);
  $start = (int)($start ?? 0);
  $end = (int)($end ?? 0);
  $allowedPerPage = is_array($allowedPerPage ?? null) ? $allowedPerPage : [25, 50, 75, 100, 150, 200];
  $search = (string)($search ?? '');
  $questionType = (string)($questionType ?? '');
  $status = (string)($status ?? 'all');
  $sort = (string)($sort ?? 'oldest');
  $allowedTypes = is_array($allowedTypes ?? null) ? $allowedTypes : ['', 'single', 'multiple', 'yes_no', 'dropdown_matrix', 'drag_drop'];
  $allowedStatuses = is_array($allowedStatuses ?? null) ? $allowedStatuses : ['all', 'active', 'inactive'];
  $messageSuccess = (string)($messageSuccess ?? '');
  $messageWarning = (string)($messageWarning ?? '');

  $buildQuestionsUrl = static function (array $overrides = []) use ($examId, $perPage, $search, $questionType, $status, $sort, $page): string {
    $params = [
      'exam_id' => $examId,
      'per_page' => $perPage,
      'search' => $search,
      'question_type' => $questionType,
      'status' => $status,
      'sort' => $sort,
      'page' => $page,
    ];
    foreach ($overrides as $k => $v) $params[$k] = $v;
    return '/admin/questions.php?' . http_build_query($params);
  };

  $typeLabels = [
    '' => 'All Types',
    'single' => 'Single MCQ',
    'multiple' => 'Multiple MCQ',
    'yes_no' => 'Yes / No Radio Buttons',
    'dropdown_matrix' => 'Drop-down Matrix',
    'drag_drop' => 'Image Select & Target',
  ];

  $statusLabels = [
    'all' => 'All Status',
    'active' => 'Active',
    'inactive' => 'Inactive',
  ];
?>
<div class="admin-page-header">
  <div>
    <h1 class="admin-page-title">Questions</h1>
    <div class="admin-page-subtitle"><span class="mono"><?= h($examId) ?></span> • <?= h((string)($exam['exam_name'] ?? '')) ?></div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-dark" href="/admin/exams.php"><i class="bi bi-arrow-left me-1"></i> Exams</a>
    <a class="btn btn-dark" href="/admin/question_new.php?exam_id=<?= urlencode($examId) ?>"><i class="bi bi-plus-lg me-1"></i> Add Question</a>
  </div>
</div>
<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="admin-kpi"><div class="admin-kpi-label">Total Questions</div><div class="admin-kpi-value"><?= (int)$total ?></div></div></div>
  <div class="col-md-4"><div class="admin-kpi"><div class="admin-kpi-label">Active</div><div class="admin-kpi-value"><?= (int)$active ?></div></div></div>
  <div class="col-md-4"><div class="admin-kpi"><div class="admin-kpi-label">Exam Type</div><div class="admin-kpi-value" style="font-size:1.15rem;">Dumps</div></div></div>
</div>
<?php if ($messageSuccess !== ''): ?><div class="alert alert-success"><?= h($messageSuccess) ?></div><?php endif; ?>
<?php if ($messageWarning !== ''): ?><div class="alert alert-warning"><?= h($messageWarning) ?></div><?php endif; ?>
<div class="admin-panel mb-3">
  <div class="p-3 border-bottom">
    <form method="get" class="row g-2 align-items-end">
      <input type="hidden" name="exam_id" value="<?= h($examId) ?>">
      <input type="hidden" name="page" value="1">

      <div class="col-12 col-lg-3">
        <label for="search" class="form-label">Search question text</label>
        <input type="text" class="form-control" id="search" name="search" value="<?= h($search) ?>" placeholder="Question text">
      </div>

      <div class="col-12 col-md-4 col-lg-2">
        <label for="question_type" class="form-label">Question Type</label>
        <select name="question_type" id="question_type" class="form-select">
          <?php foreach ($allowedTypes as $typeValue): ?>
            <option value="<?= h($typeValue) ?>" <?= ($typeValue === $questionType) ? 'selected' : '' ?>><?= h($typeLabels[$typeValue] ?? $typeValue) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-4 col-lg-2">
        <label for="status" class="form-label">Status</label>
        <select name="status" id="status" class="form-select">
          <?php foreach ($allowedStatuses as $statusValue): ?>
            <option value="<?= h($statusValue) ?>" <?= ($statusValue === $status) ? 'selected' : '' ?>><?= h($statusLabels[$statusValue] ?? $statusValue) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-4 col-lg-2">
        <label for="sort" class="form-label">Sort</label>
        <select name="sort" id="sort" class="form-select">
          <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest</option>
          <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
        </select>
      </div>

      <div class="col-12 col-md-4 col-lg-1">
        <label for="per_page" class="form-label">Per page</label>
        <select name="per_page" id="per_page" class="form-select">
          <?php foreach ($allowedPerPage as $size): ?>
            <option value="<?= (int)$size ?>" <?= ((int)$size === $perPage) ? 'selected' : '' ?>><?= (int)$size ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-lg-2">
        <div class="d-flex gap-2 flex-nowrap">
          <button type="submit" class="btn btn-dark flex-fill text-nowrap"><i class="bi bi-funnel me-1"></i>Apply</button>
          <a href="/admin/questions.php?exam_id=<?= urlencode($examId) ?>" class="btn btn-outline-secondary text-nowrap">Reset</a>
        </div>
      </div>
    </form>
  </div>

  <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 p-3 border-bottom">
    <div class="text-muted small">
      <?php if ($total > 0): ?>
        Showing <strong><?= $start ?></strong> to <strong><?= $end ?></strong> of <strong><?= (int)$total ?></strong> questions
      <?php else: ?>
        No questions found
      <?php endif; ?>
    </div>
    <div class="text-muted small">
      <?php if ($search !== '' || $questionType !== '' || $status !== 'all' || $sort !== 'oldest'): ?>
        Filters:
        <?= $search !== '' ? '<span class="badge text-bg-light border">Search: ' . h($search) . '</span> ' : '' ?>
        <?= $questionType !== '' ? '<span class="badge text-bg-light border">Type: ' . h($typeLabels[$questionType] ?? $questionType) . '</span> ' : '' ?>
        <?= $status !== 'all' ? '<span class="badge text-bg-light border">Status: ' . h($statusLabels[$status] ?? $status) . '</span> ' : '' ?>
        <?= $sort === 'newest' ? '<span class="badge text-bg-light border">Newest first</span>' : '' ?>
      <?php endif; ?>
    </div>
  </div>

  <form method="post" id="bulkQuestionsForm">
    <?= csrf_input() ?>
    <input type="hidden" name="exam_id" value="<?= h($examId) ?>">
    <input type="hidden" name="page" value="<?= (int)$page ?>">
    <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
    <input type="hidden" name="search" value="<?= h($search) ?>">
    <input type="hidden" name="question_type" value="<?= h($questionType) ?>">
    <input type="hidden" name="status" value="<?= h($status) ?>">
    <input type="hidden" name="sort" value="<?= h($sort) ?>">

    <div class="p-3 border-bottom bg-light d-flex flex-column flex-lg-row gap-2 align-items-lg-center justify-content-between">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="select_all_rows">
        <label class="form-check-label" for="select_all_rows">Select all on this page</label>
      </div>
      <div class="d-flex flex-column flex-md-row gap-2 align-items-stretch">
        <select name="bulk_action" class="form-select" style="min-width: 220px;">
          <option value="">Bulk actions</option>
          <option value="activate">Activate</option>
          <option value="deactivate">Deactivate</option>
          <option value="delete">Delete</option>
        </select>
        <button type="submit" class="btn btn-dark text-nowrap" onclick="return confirmBulkQuestionsAction();"><i class="bi bi-lightning-charge me-1"></i>Apply to selected</button>
      </div>
    </div>

    <div class="table-responsive table-wrap">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th style="width: 44px;" class="text-center"><input type="checkbox" class="form-check-input" aria-label="Select all questions" onclick="toggleAllQuestionRows(this)"></th>
            <th style="width: 70px;">#</th>
            <th>Question</th>
            <th style="width: 140px;">Type</th>
            <th style="width: 90px;" class="text-center">Pts</th>
            <th style="width: 160px;" class="text-center">Date Created</th>
            <th style="width: 120px;" class="text-center">Active</th>
            <th class="text-end" style="width: 220px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $q): ?>
            <tr>
              <td class="text-center"><input type="checkbox" class="form-check-input question-row-checkbox" name="selected_ids[]" value="<?= (int)$q['id'] ?>"></td>
              <td class="mono"><?= (int)($q['sort_order'] ?? 0) ?></td>
              <td><?= h(mb_strimwidth(trim(strip_tags((string)($q['question_text'] ?? ''))), 0, 120, '…', 'UTF-8')) ?></td>
              <td>
                <?php
                  $qtype = (string)($q['question_type'] ?? 'single');
                  if ($qtype === 'multiple') {
                    echo '<span class="badge text-bg-warning">MULTI</span>';
                  } elseif ($qtype === 'yes_no') {
                    echo '<span class="badge text-bg-secondary">YES / NO</span>';
                  } elseif ($qtype === 'dropdown_matrix') {
                    echo '<span class="badge text-bg-dark">DROP-DOWN MATRIX</span>';
                  } elseif ($qtype === 'drag_drop') {
                    $label = 'DRAG: IMAGE SELECT AND TARGET';
                    echo '<span class="badge text-bg-primary">' . h($label) . '</span>';
                  } else {
                    echo '<span class="badge text-bg-info">SINGLE</span>';
                  }
                ?>
              </td>
              <td class="text-center"><?= (int)($q['points'] ?? 0) ?></td>
              <td class="text-center"><?= !empty($q['created_at']) ? h(date('d M Y h:i A', strtotime((string)$q['created_at']))) : '-' ?></td>
              <td class="text-center"><?= ((int)($q['is_active'] ?? 0) === 1) ? '<span class="badge text-bg-success">Yes</span>' : '<span class="badge text-bg-secondary">No</span>' ?></td>
              <td class="text-end">
                <a class="btn btn-sm btn-dark" href="/admin/question_edit.php?id=<?= (int)$q['id'] ?>&exam_id=<?= urlencode($examId) ?>"><i class="bi bi-pencil me-1"></i>Edit</a>
                <a class="btn btn-sm btn-outline-danger" href="/admin/question_delete.php?id=<?= (int)$q['id'] ?>&exam_id=<?= urlencode($examId) ?>" onclick="return confirm('Delete this question?');"><i class="bi bi-trash me-1"></i>Delete</a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-5">No questions found for the selected filters.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </form>
</div>

<?php if ($totalPages > 1): ?>
  <nav aria-label="Questions pagination">
    <ul class="pagination justify-content-end">
      <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
        <a class="page-link" href="<?= h($buildQuestionsUrl(['page' => max(1, $page - 1)])) ?>">Previous</a>
      </li>

      <?php $windowStart = max(1, $page - 2); ?>
      <?php $windowEnd = min($totalPages, $page + 2); ?>

      <?php if ($windowStart > 1): ?>
        <li class="page-item"><a class="page-link" href="<?= h($buildQuestionsUrl(['page' => 1])) ?>">1</a></li>
        <?php if ($windowStart > 2): ?>
          <li class="page-item disabled"><span class="page-link">…</span></li>
        <?php endif; ?>
      <?php endif; ?>

      <?php for ($i = $windowStart; $i <= $windowEnd; $i++): ?>
        <li class="page-item <?= ($i === $page) ? 'active' : '' ?>">
          <a class="page-link" href="<?= h($buildQuestionsUrl(['page' => $i])) ?>"><?= $i ?></a>
        </li>
      <?php endfor; ?>

      <?php if ($windowEnd < $totalPages): ?>
        <?php if ($windowEnd < ($totalPages - 1)): ?>
          <li class="page-item disabled"><span class="page-link">…</span></li>
        <?php endif; ?>
        <li class="page-item"><a class="page-link" href="<?= h($buildQuestionsUrl(['page' => $totalPages])) ?>"><?= $totalPages ?></a></li>
      <?php endif; ?>

      <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
        <a class="page-link" href="<?= h($buildQuestionsUrl(['page' => min($totalPages, $page + 1)])) ?>">Next</a>
      </li>
    </ul>
  </nav>
<?php endif; ?>

<script>
function toggleAllQuestionRows(source) {
  document.querySelectorAll('.question-row-checkbox').forEach(function (cb) {
    cb.checked = !!source.checked;
  });
  var top = document.getElementById('select_all_rows');
  if (top) top.checked = !!source.checked;
}

(function () {
  var top = document.getElementById('select_all_rows');
  if (top) {
    top.addEventListener('change', function () {
      toggleAllQuestionRows(this);
    });
  }
})();

function confirmBulkQuestionsAction() {
  var action = document.querySelector('select[name="bulk_action"]').value;
  var selected = document.querySelectorAll('.question-row-checkbox:checked').length;

  if (!action) {
    alert('Please choose a bulk action.');
    return false;
  }
  if (selected < 1) {
    alert('Please select at least one question.');
    return false;
  }
  if (action === 'delete') {
    return confirm('Delete selected question(s)? This cannot be undone.');
  }
  return true;
}
</script>
