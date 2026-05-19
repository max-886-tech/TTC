<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/quiz.php';
require_once __DIR__ . '/../lib/r2.php';

require_permission('exams.manage');

$pdo = db();
quiz_safe_ensure_schema($pdo);

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM exams WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$exam = $stmt->fetch();
if (!$exam) {
  header('Location: /admin/exams.php');
  exit;
}
if (($exam['resource_type'] ?? '') !== 'enbl') {
  header('Location: /admin/exam_edit.php?id=' . (int)$id);
  exit;
}

$msg = '';
$r2_list_error = null;
$r2_prefixes = [];
$r2_files_by_prefix = [];
$currentKey = (string)($exam['r2_key'] ?? '');
$currentPrefix = '';
if ($currentKey !== '') {
  $parts = explode('/', ltrim($currentKey, '/'), 2);
  $currentPrefix = count($parts) > 1 ? trim($parts[0]) : '(root)';
}

try {
  $r2_files_by_prefix = r2_list_grouped_objects('', 1000);
  $r2_prefixes = array_values(array_keys($r2_files_by_prefix));
} catch (Exception $e) {
  $r2_list_error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $exam_name = trim($_POST['exam_name'] ?? '');
  $selected_prefix = trim($_POST['r2_prefix'] ?? '');
  $r2_key = ltrim(trim($_POST['r2_key'] ?? ''), '/');
  $r2_url = $r2_key !== '' ? r2_public_url($r2_key) : null;

  if ($exam_name === '') {
    $msg = 'Dumps Name is required.';
  } else {
    $upd = $pdo->prepare("UPDATE exams SET exam_name=?, resource_type='enbl', r2_key=?, r2_url=?, updated_at=? WHERE id=?");
    $upd->execute([
      $exam_name,
      $r2_key ?: null,
      $r2_url,
      utc_now_sql(),
      $id,
    ]);

    header('Location: /admin/exams.php');
    exit;
  }
}

require_once __DIR__ . '/_header.php';
?>
<div class="admin-page-header">
  <div>
    <h1 class="admin-page-title">Edit TrueCerts Dumps</h1>
    <div class="admin-page-subtitle mono"><?= h($exam['exam_id']) ?></div>
  </div>
  <a class="btn btn-outline-dark" href="/admin/exams.php"><i class="bi bi-arrow-left me-1"></i> Back</a>
</div>

<?php if ($msg): ?>
  <div class="alert alert-danger rounded-4"><?= h($msg) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm rounded-4">
  <div class="card-body p-4">
    <form method="post">
      <?= csrf_field() ?>

      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Dumps ID</label>
          <input class="form-control mono" value="<?= h($exam['exam_id']) ?>" disabled>
        </div>
        <div class="col-md-8">
          <label class="form-label">Dumps Name</label>
          <input class="form-control" name="exam_name" value="<?= h($_POST['exam_name'] ?? $exam['exam_name']) ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Select Prefix</label>
          <?php if ($r2_list_error): ?>
            <div class="alert alert-warning rounded-4">R2 list error: <?= h($r2_list_error) ?></div>
          <?php endif; ?>
          <select class="form-select" name="r2_prefix" id="r2-prefix-select">
            <option value="">-- Select prefix --</option>
            <?php foreach ($r2_prefixes as $prefix): ?>
              <option value="<?= h($prefix) ?>" <?= $prefix === (string)($_POST['r2_prefix'] ?? $currentPrefix) ? 'selected' : '' ?>><?= h($prefix) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-8">
          <label class="form-label">Dumps File (R2)</label>
          <input type="text" class="form-control mb-2" id="r2-file-search" placeholder="Search file inside selected prefix" disabled>
          <select class="form-select" name="r2_key" id="r2-file-select">
            <option value="">-- Select file --</option>
          </select>
          <div class="form-text">After selecting a prefix, search and choose only files from that folder.</div>
        </div>
      </div>

      <div class="d-flex gap-2 mt-4">
        <button class="btn btn-dark"><i class="bi bi-check-lg me-1"></i> Save</button>
        <a class="btn btn-outline-dark" href="/admin/exams.php">Cancel</a>
      </div>
    </form>
  </div>
</div>

<script>
  (function () {
    const filesByPrefix = <?= json_encode($r2_files_by_prefix, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const prefixSelect = document.getElementById('r2-prefix-select');
    const fileSelect = document.getElementById('r2-file-select');
    const fileSearch = document.getElementById('r2-file-search');
    const selectedFile = <?= json_encode((string)($_POST['r2_key'] ?? $exam['r2_key'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    if (!prefixSelect || !fileSelect || !fileSearch) return;

    function renderFiles(resetSelection) {
      const prefix = prefixSelect.value || '';
      const items = filesByPrefix[prefix] || [];
      const search = (fileSearch.value || '').trim().toLowerCase();
      const filteredItems = search
        ? items.filter(function (item) {
            const label = String(item.label || item.key || '').toLowerCase();
            return label.indexOf(search) !== -1;
          })
        : items;
      const placeholder = prefix ? '-- Select file --' : '-- Select prefix first --';

      fileSelect.innerHTML = '';
      const firstOption = document.createElement('option');
      firstOption.value = '';
      firstOption.textContent = placeholder;
      fileSelect.appendChild(firstOption);

      filteredItems.forEach(function (item) {
        const opt = document.createElement('option');
        opt.value = item.key || '';
        opt.textContent = item.label || item.key || '';
        if (!resetSelection && (item.key || '') === selectedFile) {
          opt.selected = true;
        }
        fileSelect.appendChild(opt);
      });

      fileSelect.disabled = !prefix;
      fileSearch.disabled = !prefix;

      if (!prefix) {
        fileSearch.value = '';
      }

      if (resetSelection) {
        fileSelect.selectedIndex = 0;
      }
    }

    prefixSelect.addEventListener('change', function () {
      fileSearch.value = '';
      renderFiles(true);
    });

    fileSearch.addEventListener('input', function () {
      renderFiles(true);
    });

    renderFiles(false);
  })();
</script>
<?php require_once __DIR__ . '/_footer.php'; ?>
