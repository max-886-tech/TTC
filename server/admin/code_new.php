<?php
require_once __DIR__ . '/_header.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/audit.php';
require_once __DIR__ . '/../lib/r2.php';

$pdo = db();
$msg = '';
$new_code = null;
$r2_msg = '';

/**
 * Build INSERT statement dynamically so optional columns (like user_name, r2_key, r2_url)
 * don't break when the database schema hasn't been updated yet.
 */
function build_access_codes_insert(PDO $pdo, array $data): array {
  $cols = [];
  $qs   = [];
  $vals = [];

  foreach ($data as $col => $val) {
    $isOptional = in_array($col, ['user_name', 'r2_key', 'r2_url'], true);
    if ($isOptional && !db_has_column($pdo, 'access_codes', $col)) {
      continue;
    }
    $cols[] = $col;
    $qs[] = '?';
    $vals[] = $val;
  }

  $sql = "INSERT INTO access_codes (" . implode(',', $cols) . ") VALUES (" . implode(',', $qs) . ")";
  return [$sql, $vals];
}

// -------------------------
// R2: list objects for dropdown
// -------------------------
$r2_prefix_filter = trim($_GET['r2_prefix'] ?? '');
$r2_files = [];
$r2_list_error = null;
try {
  $r2_files = r2_list_objects($r2_prefix_filter, 500);
} catch (Exception $e) {
  $r2_list_error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  // -------------------------
  // 1) Read + validate inputs
  // -------------------------
  $exam_name = trim($_POST['exam_name'] ?? '');
  $exam_id   = trim($_POST['exam_id'] ?? '');   // Dumps Code (mandatory)

  $user_name    = trim($_POST['user_name'] ?? '');
  $user_email   = trim($_POST['user_email'] ?? '');
  $user_phone   = trim($_POST['user_phone'] ?? '');
  $user_address = trim($_POST['user_address'] ?? '');

  // Select existing object from R2
  $r2_key = trim($_POST['r2_key'] ?? '');

  $max_uses = (int)($_POST['max_uses'] ?? 1);
  $days = (int)($_POST['days'] ?? 0);
  $expires_local = $_POST['expires_local'] ?? '';
  $note = trim($_POST['note'] ?? '');
  $is_active = isset($_POST['is_active']) ? 1 : 0;

  if ($exam_name === '' || $exam_id === '' || $user_name === '' || $r2_key === '') {
    $msg = 'Dumps Name, Dumps Code, User Name, and R2 File are required.';
  } else {
    if ($max_uses <= 0) $max_uses = 1;

    $code = gen_code();
    $hash = code_hash($code);

    $expires_at = parse_datetime_local_to_utc($expires_local);
    if (!$expires_at) $expires_at = parse_days_to_expires_at($days);

    // -------------------------
    // 2) Store selected R2 object key
    // -------------------------
    $r2_key = ltrim($r2_key, '/');
    $r2_url = r2_public_url($r2_key);
    $r2_msg = $r2_url ? "Selected R2 file: {$r2_url}" : "Selected R2 key: {$r2_key}";

    // -------------------------
    // 3) Insert access code row (schema-safe)
    // -------------------------
    $data = [
      'exam_id' => $exam_id,
      'exam_name' => $exam_name,
      'code_hash' => $hash,
      'code_plain' => $code,
      'max_uses' => $max_uses,
      'expires_at' => $expires_at,
      'is_active' => $is_active,
      'note' => $note ?: null,
      'user_name' => $user_name ?: null,
      'user_email' => $user_email ?: null,
      'user_phone' => $user_phone ?: null,
      'user_address' => $user_address ?: null,
      'r2_key' => $r2_key,
      'r2_url' => $r2_url,
      'created_by' => (int)$me['id'],
      'updated_by' => (int)$me['id'],
    ];

    // If DB doesn't have these columns yet, preserve the info in note (so nothing is lost).
    if ($user_name && !db_has_column($pdo, 'access_codes', 'user_name')) {
      $data['note'] = trim((string)$data['note'] . "\nUser Name: {$user_name}") ?: null;
    }
    if ($r2_key && !db_has_column($pdo, 'access_codes', 'r2_key')) {
      $data['note'] = trim((string)$data['note'] . "\nR2 Key: {$r2_key}") ?: null;
    }
    if ($r2_url && !db_has_column($pdo, 'access_codes', 'r2_url')) {
      $data['note'] = trim((string)$data['note'] . "\nR2 URL: {$r2_url}") ?: null;
    }

    [$sql, $vals] = build_access_codes_insert($pdo, $data);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($vals);

    $newId = (int)$pdo->lastInsertId();

    audit_log_event('code_create', 'access_codes', $newId, [
      'exam_id' => $exam_id,
      'exam_name' => $exam_name,
      'user_name' => $user_name,
      'r2_key' => $r2_key,
      'r2_url' => $r2_url,
      'max_uses' => $max_uses,
      'expires_at_utc' => $expires_at,
      'is_active' => $is_active,
      'note' => $note ?: null,
    ]);

    $new_code = $code;
    $msg = 'Code generated successfully.';
  }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="m-0">Generate New Code</h4>
  <a class="btn btn-outline-secondary" href="/admin/">Back</a>
</div>

<?php if ($msg || $r2_msg): ?>
  <div class="alert <?= $new_code ? 'alert-success' : 'alert-danger' ?>">
    <?php if ($msg): ?>
      <div><?= h($msg) ?></div>
    <?php endif; ?>
    <?php if ($r2_msg): ?>
      <div class="small text-muted mt-1"><?= h($r2_msg) ?></div>
    <?php endif; ?>
    <?php if ($new_code): ?>
      <hr>
      <div class="mb-2">New Code:</div>
      <div class="d-flex gap-2 align-items-center">
        <code class="mono fs-5" id="newcode"><?= h($new_code) ?></code>
        <button class="btn btn-sm btn-outline-dark" type="button" onclick="copyCode()">Copy</button>
      </div>
      <div class="text-muted small mt-2">Tip: Save this code somewhere safe.</div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<form method="post" class="card p-3 shadow-sm" style="max-width: 920px;">
  <?= csrf_input() ?>

  <div class="row g-3">

    <div class="col-md-6">
      <label class="form-label">Dumps Name *</label>
      <input class="form-control" name="exam_name" required placeholder="e.g. Microsoft Azure AZ-700">
    </div>

    <div class="col-md-6">
      <label class="form-label">Dumps Code *</label>
      <input class="form-control" name="exam_id" required placeholder="e.g. AZ-700">
    </div>

    <div class="col-md-4">
      <label class="form-label">User Name *</label>
      <input class="form-control" name="user_name" placeholder="Customer name" required>
    </div>

    <div class="col-md-4">
      <label class="form-label">User Email (optional)</label>
      <input class="form-control" name="user_email" type="email" placeholder="customer@email.com">
    </div>

    <div class="col-md-4">
      <label class="form-label">User Phone (optional)</label>
      <input class="form-control" name="user_phone" placeholder="+91...">
    </div>

    <div class="col-md-4">
      <label class="form-label">User Address (optional)</label>
      <input class="form-control" name="user_address" placeholder="City, State">
    </div>

    <div class="col-md-6">
      <label class="form-label">R2 Prefix (optional)</label>
      <input class="form-control" name="r2_prefix" value="<?= h($r2_prefix_filter) ?>" placeholder="e.g. TTC/AZ-700">
      <div class="text-muted small">Tip: Use prefix to narrow the dropdown if your bucket has many files.</div>
    </div>

    <div class="col-md-6 d-flex align-items-end">
      <a class="btn btn-outline-primary w-100" href="?r2_prefix=<?= urlencode($r2_prefix_filter) ?>">Load R2 Files</a>
    </div>

    <?php if ($r2_list_error): ?>
      <div class="col-12">
        <div class="alert alert-warning mb-0">R2 List Error: <?= h($r2_list_error) ?></div>
      </div>
    <?php endif; ?>

    <div class="col-12">
      <label class="form-label">Select File from R2 *</label>
      <select class="form-select" name="r2_key" required>
        <option value="">-- Select file --</option>
        <?php foreach ($r2_files as $f): ?>
          <option value="<?= h($f['key']) ?>"><?= h($f['key']) ?> (<?= number_format($f['size']/1024, 1) ?> KB)</option>
        <?php endforeach; ?>
      </select>
      <div class="text-muted small">Bucket: <?= h((string)r2_config('R2_BUCKET', '')) ?> • Domain: <?= h((string)r2_config('R2_PUBLIC_BASE', '')) ?></div>
    </div>

    <div class="col-md-3">
      <label class="form-label">Max Uses</label>
      <input class="form-control" type="number" name="max_uses" value="1" min="1">
    </div>

    <div class="col-md-3 d-flex align-items-end">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="is_active" checked>
        <label class="form-check-label">Active</label>
      </div>
    </div>

    <div class="col-md-6">
      <label class="form-label">Expiry (datetime-local)</label>
      <input class="form-control" type="datetime-local" name="expires_local">
      <div class="text-muted small">If set, this will be used.</div>
    </div>

    <div class="col-md-6">
      <label class="form-label">OR Expiry in Days</label>
      <input class="form-control" type="number" name="days" value="0" min="0">
    </div>

    <div class="col-12">
      <label class="form-label">Note (optional)</label>
      <input class="form-control" name="note" placeholder="Order ID / WhatsApp / etc">
    </div>

  </div>

  <div class="mt-3">
    <button class="btn btn-primary" type="submit">Generate</button>
  </div>
</form>

<script>
function copyCode() {
  const t = document.getElementById('newcode').innerText;
  navigator.clipboard.writeText(t);
}
</script>

<?php require_once __DIR__ . '/_footer.php'; ?>
