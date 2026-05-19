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

$msg = '';
$r2_prefix_filter = trim($_GET['r2_prefix'] ?? '');
$r2_files = [];
$r2_list_error = null;
$r2_prefixes = [];
$r2_files_by_prefix = [];
try {
  $r2_files = r2_list_objects('', 1000);
  $r2_files_by_prefix = r2_group_objects_by_prefix($r2_files);
  $r2_prefixes = array_values(array_keys($r2_files_by_prefix));
} catch (Exception $e) {
  $r2_list_error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $exam_id_input = trim($_POST['exam_id'] ?? '');
  $exam_id_base = preg_replace('/(?:_mock|_enbl|_ttc)+$/i', '', $exam_id_input);
  $exam_id = $exam_id_base !== '' ? ($exam_id_base . '_ttc') : '';
  $exam_name = trim($_POST['exam_name'] ?? '');
  $selected_prefix = trim($_POST['r2_prefix'] ?? '');
  $r2_key = ltrim(trim($_POST['r2_key'] ?? ''), '/');
  $r2_url = $r2_key !== '' ? r2_public_url($r2_key) : null;

  if ($exam_id === '' || $exam_name === '') {
    $msg = 'Dumps ID and Dumps Name are required.';
  } else {
    $check = $pdo->prepare("SELECT id FROM exams WHERE exam_id = ? LIMIT 1");
    $check->execute([$exam_id]);
    $existing = $check->fetch();

    if ($existing) {
      $msg = 'Dumps ID already present. Please use a different Dumps ID.';
    } else {
      $stmt = $pdo->prepare("INSERT INTO exams (exam_id, exam_name, resource_type, r2_key, r2_url, duration_minutes, shuffle_questions, shuffle_choices, terms_text, ui_disable_previous, ui_hide_points, ui_hide_question_type, ui_hide_explanation, ui_hide_flag_button, mode_settings_json, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
      $stmt->execute([
        $exam_id,
        $exam_name,
        'enbl',
        $r2_key ?: null,
        $r2_url,
        30,
        0,
        0,
        null,
        0,
        0,
        0,
        0,
        0,
        json_encode(new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        utc_now_sql(),
      ]);

      header('Location: /admin/exams.php');
      exit;
    }
  }
}

require_once __DIR__ . '/_header.php';
?>
<div class="admin-page-header">
  <div>
    <h1 class="admin-page-title">New TrueCerts Dumps</h1>
    <div class="admin-page-subtitle">Create a TrueCerts Dumps file entry.</div>
  </div>
  <a class="btn btn-outline-dark" href="/admin/exams.php"><i class="bi bi-arrow-left me-1"></i> Back</a>
</div>

<?php if ($msg): ?>
  <div class="alert alert-danger rounded-4"><?= h($msg) ?></div>
<?php elseif (!empty($_POST['exam_id'])): ?>
  <div class="alert alert-info rounded-4">Dumps ID will be saved as <span class="mono"><?= h(preg_replace('/(?:_mock|_enbl|_ttc)+$/i', '', trim((string)$_POST['exam_id'])) . '_ttc') ?></span>.</div>
<?php endif; ?>

<div class="card border-0 shadow-sm rounded-4">
  <div class="card-body p-4">
    <form method="post">
      <?= csrf_field() ?>

      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Dumps ID</label>
          <input class="form-control mono" name="exam_id" placeholder="ENCOR-350-401" value="<?= h($_POST['exam_id'] ?? '') ?>" required>
          <div class="form-text">Suffix <span class="mono">_ttc</span> will be added automatically when you save.</div>
        </div>
        <div class="col-md-8">
          <label class="form-label">Dumps Name</label>
          <input class="form-control" name="exam_name" placeholder="Cisco ENCOR 350-401 Dumps" value="<?= h($_POST['exam_name'] ?? '') ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Select Prefix</label>
          <?php if ($r2_list_error): ?>
            <div class="alert alert-warning rounded-4">R2 list error: <?= h($r2_list_error) ?></div>
          <?php endif; ?>
          <select class="form-select" name="r2_prefix" id="r2-prefix-select">
            <option value="">-- Select prefix --</option>
            <?php foreach ($r2_prefixes as $prefix): ?>
              <option value="<?= h($prefix) ?>" <?= $prefix === (string)($_POST['r2_prefix'] ?? $r2_prefix_filter) ? 'selected' : '' ?>><?= h($prefix) ?></option>
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
    const selectedFile = <?= json_encode((string)($_POST['r2_key'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

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
