<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/r2.php';
require_once __DIR__ . '/../lib/enbl_converter.php';

require_permission('exams.manage');

$msg = '';
$msgType = 'success';
$r2_list_error = null;
$selectedPrefix = trim((string)($_GET['prefix'] ?? $_POST['convert_prefix'] ?? $_POST['upload_prefix'] ?? $_POST['prefix_name'] ?? ''));
$r2_files_by_prefix = [];
$r2_prefixes = [];
$totalFiles = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = trim((string)($_POST['action'] ?? ''));

  try {
    if ($action === 'create_prefix') {
      $prefix = trim((string)($_POST['prefix_name'] ?? ''));
      r2_create_prefix($prefix);
      $msg = 'Prefix created successfully.';
    } elseif ($action === 'delete_prefix') {
      $prefix = trim((string)($_POST['prefix_name'] ?? ''));
      $deleted = r2_delete_prefix($prefix);
      $msg = 'Prefix deleted successfully. Removed ' . (int)$deleted . ' file(s).';
    } elseif ($action === 'upload_file') {
      $prefix = r2_normalize_prefix((string)($_POST['upload_prefix'] ?? ''));
      if ($prefix === '') {
        throw new Exception('Please select a prefix first.');
      }
      if (!isset($_FILES['upload_file']) || (int)($_FILES['upload_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new Exception('Please choose a file to upload.');
      }
      $name = basename((string)$_FILES['upload_file']['name']);
      if ($name === '') {
        throw new Exception('Invalid upload file name.');
      }
      $tmp = (string)$_FILES['upload_file']['tmp_name'];
      $body = (string)file_get_contents($tmp);
      $type = (string)($_FILES['upload_file']['type'] ?? 'application/octet-stream');
      $key = $prefix . '/' . $name;
      r2_put_object($key, $body, $type !== '' ? $type : 'application/octet-stream');
      $msg = 'File uploaded successfully.';
      $selectedPrefix = $prefix;
    } elseif ($action === 'delete_file') {
      $key = trim((string)($_POST['file_key'] ?? ''));
      r2_delete_object($key);
      $msg = 'File deleted successfully.';
    } elseif ($action === 'convert_pdf_to_ttc') {
      $prefix = r2_normalize_prefix((string)($_POST['convert_prefix'] ?? ''));
      if ($prefix === '') {
        throw new Exception('Please select a prefix for the converted TTC file.');
      }
      if (!isset($_FILES['source_pdf']) || (int)($_FILES['source_pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new Exception('Please choose a PDF file to convert.');
      }
      $sourceName = basename((string)($_FILES['source_pdf']['name'] ?? ''));
      if ($sourceName === '') {
        throw new Exception('Invalid PDF file name.');
      }
      $ext = strtolower((string)pathinfo($sourceName, PATHINFO_EXTENSION));
      if ($ext !== 'pdf') {
        throw new Exception('Only PDF files can be converted to TTC.');
      }
      $tmp = (string)$_FILES['source_pdf']['tmp_name'];
      $pdfBytes = (string)file_get_contents($tmp);
      if ($pdfBytes === '') {
        throw new Exception('Failed to read the uploaded PDF file.');
      }
      $examId = trim((string)($_POST['convert_exam_id'] ?? ''));
      if ($examId === '') {
        $examId = (string)pathinfo($sourceName, PATHINFO_FILENAME);
      }
      $user = trim((string)($_POST['convert_user'] ?? ''));
      $expiryAbs = trim((string)($_POST['convert_expiry_abs'] ?? ''));
      $expiryRelHours = max(0, (int)($_POST['convert_expiry_rel_hours'] ?? 0));
      $watermarkRaw = str_replace(["
", "
"], "
", (string)($_POST['convert_watermark'] ?? ''));
      $watermark = array_values(array_filter(array_map('trim', explode("
", $watermarkRaw)), static fn($line) => $line !== ''));
      $targetName = trim((string)($_POST['convert_output_name'] ?? ''));
      if ($targetName === '') {
        $targetName = (string)pathinfo($sourceName, PATHINFO_FILENAME) . '.ttc';
      }
      $targetName = preg_replace('/[^A-Za-z0-9._ -]+/', '-', $targetName) ?: 'converted.ttc';
      if (!preg_match('/\.ttc$/i', $targetName)) {
        $targetName .= '.ttc';
      }

      $build = enbl_create_from_pdf_bytes($pdfBytes, [
        'examId' => $examId,
        'user' => $user,
        'expiryAbs' => $expiryAbs,
        'expiryRelHours' => $expiryRelHours,
        'watermark' => $watermark,
      ]);
      $key = $prefix . '/' . ltrim($targetName, '/');
      r2_put_object($key, $build['binary'], 'application/octet-stream');
      $msg = 'PDF converted to TTC and uploaded successfully. File ID: ' . (string)$build['file_id'];
      $selectedPrefix = $prefix;
    } else {
      throw new Exception('Unknown action.');
    }
  } catch (Exception $e) {
    $msg = $e->getMessage();
    $msgType = 'danger';
  }
}

try {
  $r2_files_by_prefix = r2_list_grouped_objects('', 1000);
  $r2_prefixes = array_values(array_keys($r2_files_by_prefix));
  foreach ($r2_files_by_prefix as $items) {
    $totalFiles += count($items);
  }
} catch (Exception $e) {
  $r2_list_error = $e->getMessage();
}

require_once __DIR__ . '/_header.php';
?>
<div class="admin-page-header">
  <div>
    <h1 class="admin-page-title">R2 Manager</h1>
    <div class="admin-page-subtitle">Create prefixes, upload files, and remove unused files from Cloudflare R2.</div>
  </div>
  <a class="btn btn-outline-dark" href="/admin/exams.php"><i class="bi bi-arrow-left me-1"></i> Back</a>
</div>

<?php if ($msg): ?>
  <div class="alert alert-<?= h($msgType) ?> rounded-4"><?= h($msg) ?></div>
<?php endif; ?>
<?php if ($r2_list_error): ?>
  <div class="alert alert-warning rounded-4">R2 list error: <?= h($r2_list_error) ?></div>
<?php endif; ?>

<div class="row g-4 mb-4">
  <div class="col-md-4">
    <div class="card border-0 shadow-sm rounded-4 h-100">
      <div class="card-body p-4">
        <div class="text-muted small mb-2">Prefixes</div>
        <div class="display-6 fw-semibold"><?= count($r2_prefixes) ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card border-0 shadow-sm rounded-4 h-100">
      <div class="card-body p-4">
        <div class="text-muted small mb-2">Files</div>
        <div class="display-6 fw-semibold"><?= (int)$totalFiles ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card border-0 shadow-sm rounded-4 h-100">
      <div class="card-body p-4">
        <div class="text-muted small mb-2">Selected Prefix</div>
        <div class="fs-4 fw-semibold text-truncate"><?= h($selectedPrefix !== '' ? $selectedPrefix : 'None') ?></div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm rounded-4 mb-4">
      <div class="card-body p-4">
        <h2 class="h5 mb-3">Create Prefix</h2>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create_prefix">
          <label class="form-label">Prefix Name</label>
          <input class="form-control" name="prefix_name" placeholder="Cisco 2026" required>
          <div class="form-text mb-3">A hidden .keep file will be added so the prefix stays visible.</div>
          <button class="btn btn-dark"><i class="bi bi-plus-lg me-1"></i> Create Prefix</button>
        </form>
      </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4">
      <div class="card-body p-4">
        <h2 class="h5 mb-3">Delete Prefix</h2>
        <form method="post" onsubmit="return confirm('Delete this prefix and all files inside it? This cannot be undone.');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_prefix">
          <label class="form-label">Select Prefix</label>
          <select class="form-select" name="prefix_name" required>
            <option value="">-- Select prefix --</option>
            <?php foreach ($r2_prefixes as $prefix): ?>
              <option value="<?= h($prefix) ?>" <?= $prefix === $selectedPrefix ? 'selected' : '' ?>><?= h($prefix) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text mb-3">This removes every file inside the selected prefix.</div>
          <button class="btn btn-outline-danger"><i class="bi bi-trash me-1"></i> Delete Prefix</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card border-0 shadow-sm rounded-4 mb-4">
      <div class="card-body p-4">
        <h2 class="h5 mb-3">Convert PDF to TTC</h2>
        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="convert_pdf_to_ttc">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Prefix</label>
              <select class="form-select" name="convert_prefix" required>
                <option value="">-- Select prefix --</option>
                <?php foreach ($r2_prefixes as $prefix): ?>
                  <option value="<?= h($prefix) ?>" <?= $prefix === $selectedPrefix ? 'selected' : '' ?>><?= h($prefix) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">PDF File</label>
              <input type="file" class="form-control" name="source_pdf" accept="application/pdf,.pdf" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Output TTC Name</label>
              <input type="text" class="form-control" name="convert_output_name" placeholder="sample.ttc">
            </div>
            <div class="col-md-6">
              <label class="form-label">Exam ID</label>
              <input type="text" class="form-control" name="convert_exam_id" placeholder="Auto from PDF name if left blank">
            </div>
            <div class="col-md-3">
              <label class="form-label">User</label>
              <input type="text" class="form-control" name="convert_user" placeholder="Optional">
            </div>
            <div class="col-md-3">
              <label class="form-label">Expiry (Relative Hours)</label>
              <input type="number" min="0" class="form-control" name="convert_expiry_rel_hours" value="0">
            </div>
            <div class="col-md-6">
              <label class="form-label">Expiry (Absolute)</label>
              <input type="text" class="form-control" name="convert_expiry_abs" placeholder="Optional, e.g. 2026-12-31T23:59:59Z">
            </div>
            <div class="col-md-6">
              <label class="form-label">Watermark Lines</label>
              <textarea class="form-control" name="convert_watermark" rows="3" placeholder="One line per row"></textarea>
            </div>
            <div class="col-12 d-flex justify-content-end">
              <button class="btn btn-dark"><i class="bi bi-file-earmark-lock2 me-1"></i> Convert & Upload</button>
            </div>
          </div>
          <div class="form-text mt-3">This keeps the TrueCerts Reader unchanged and generates .ttc dumps files from PHP using the existing encryption format.</div>
        </form>
      </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 mb-4">
      <div class="card-body p-4">
        <h2 class="h5 mb-3">Upload File</h2>
        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="upload_file">
          <div class="row g-3 align-items-end">
            <div class="col-md-5">
              <label class="form-label">Prefix</label>
              <select class="form-select" name="upload_prefix" required>
                <option value="">-- Select prefix --</option>
                <?php foreach ($r2_prefixes as $prefix): ?>
                  <option value="<?= h($prefix) ?>" <?= $prefix === $selectedPrefix ? 'selected' : '' ?>><?= h($prefix) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-5">
              <label class="form-label">File</label>
              <input type="file" class="form-control" name="upload_file" required>
            </div>
            <div class="col-md-2 d-grid">
              <button class="btn btn-dark"><i class="bi bi-upload me-1"></i> Upload</button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4">
      <div class="card-body p-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <h2 class="h5 mb-0">Files by Prefix</h2>
          <form method="get" class="d-flex gap-2">
            <select class="form-select" name="prefix" onchange="this.form.submit()">
  <option value="" <?= $selectedPrefix === '' ? 'selected' : '' ?>>-- Select prefix --</option>
  <?php foreach ($r2_prefixes as $prefix): ?>
    <option value="<?= h($prefix) ?>" <?= $prefix === $selectedPrefix ? 'selected' : '' ?>><?= h($prefix) ?></option>
  <?php endforeach; ?>
</select>
<?php if ($selectedPrefix !== ''): ?>
  <a class="btn btn-outline-secondary" href="/admin/r2_manager.php">Clear</a>
<?php endif; ?>
          </form>
        </div>

        <?php if (!$r2_prefixes): ?>
  <div class="text-muted">No prefixes found yet.</div>
<?php elseif ($selectedPrefix === ''): ?>
  <div class="text-muted">Please select a prefix to view files.</div>
<?php elseif (!isset($r2_files_by_prefix[$selectedPrefix])): ?>
  <div class="text-muted">No files found for the selected prefix.</div>
<?php else: ?>
  <?php $items = $r2_files_by_prefix[$selectedPrefix]; ?>
  <div class="border rounded-4 p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
      <div>
        <div class="fw-semibold"><?= h($selectedPrefix) ?></div>
        <div class="text-muted small"><?= count($items) ?> file(s)</div>
      </div>
    </div>

    <?php if (!$items): ?>
      <div class="text-muted small">Only placeholder exists in this prefix.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead>
            <tr>
              <th>File</th>
              <th class="text-nowrap">Size</th>
              <th class="text-nowrap">Last Modified</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $item): ?>
              <tr>
                <td>
                  <div class="fw-medium"><?= h($item['label']) ?></div>
                  <div class="text-muted small mono"><?= h($item['key']) ?></div>
                </td>
                <td class="text-nowrap"><?= number_format((int)$item['size']) ?> B</td>
                <td class="text-nowrap"><?= h($item['last_modified'] ?: '-') ?></td>
                <td class="text-end">
                  <form method="post" onsubmit="return confirm('Delete this file?');" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_file">
                    <input type="hidden" name="file_key" value="<?= h($item['key']) ?>">
                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i> Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/_footer.php'; ?>
