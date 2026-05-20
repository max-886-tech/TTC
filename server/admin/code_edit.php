<?php
require_once __DIR__ . '/_header.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/audit.php';
require_once __DIR__ . '/../lib/r2.php';

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { echo "Missing id"; exit; }

$stmt = $pdo->prepare("SELECT * FROM access_codes WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) { echo "Not found"; exit; }

$msg = '';

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

  $exam_name = trim($_POST['exam_name'] ?? '');
  $exam_id   = trim($_POST['exam_id'] ?? '');

  $user_email   = trim($_POST['user_email'] ?? '');
  $user_phone   = trim($_POST['user_phone'] ?? '');
  $user_address = trim($_POST['user_address'] ?? '');

  // Optional: change R2 file (leave blank to keep current)
  $new_r2_key = trim($_POST['r2_key'] ?? '');

  $max_uses = (int)($_POST['max_uses'] ?? 1);
  $expires_local = $_POST['expires_local'] ?? '';
  $note = trim($_POST['note'] ?? '');
  $is_active = isset($_POST['is_active']) ? 1 : 0;

  if ($exam_name === '' || $exam_id === '') {
    $msg = "Dumps Name and Dumps Code are required.";
  } else {
    if ($max_uses <= 0) $max_uses = 1;
    $expires_at = parse_datetime_local_to_utc($expires_local);

    // Build UPDATE dynamically to support optional schema columns (r2_key, r2_url)
    $sets = [
      'exam_name=?',
      'exam_id=?',
      'user_email=?',
      'user_phone=?',
      'user_address=?',
      'max_uses=?',
      'expires_at=?',
      'is_active=?',
      'note=?',
      'updated_by=?'
    ];
    $vals = [
      $exam_name,
      $exam_id,
      $user_email ?: null,
      $user_phone ?: null,
      $user_address ?: null,
      $max_uses,
      $expires_at,
      $is_active,
      $note ?: null,
      (int)$me['id'],
    ];

    $audit_extra = [];
    if ($new_r2_key !== '') {
      $new_r2_key = ltrim($new_r2_key, '/');
      $new_r2_url = r2_public_url($new_r2_key);

      if (db_has_column($pdo, 'access_codes', 'r2_key')) {
        $sets[] = 'r2_key=?';
        $vals[] = $new_r2_key;
        $audit_extra['r2_key'] = $new_r2_key;
      } else {
        // preserve in note if column doesn't exist
        $note2 = trim((string)($note ?: '') . "\nR2 Key: {$new_r2_key}");
        $sets[8] = 'note=?';
        $vals[8] = $note2 ?: null;
        $audit_extra['r2_key'] = $new_r2_key;
      }

      if ($new_r2_url !== '') {
        if (db_has_column($pdo, 'access_codes', 'r2_url')) {
          $sets[] = 'r2_url=?';
          $vals[] = $new_r2_url;
          $audit_extra['r2_url'] = $new_r2_url;
        } else {
          // preserve in note if column doesn't exist
          $note3 = trim((string)($vals[8] ?? ($note ?: '')) . "\nR2 URL: {$new_r2_url}");
          $sets[8] = 'note=?';
          $vals[8] = $note3 ?: null;
          $audit_extra['r2_url'] = $new_r2_url;
        }
      }
    }

    $sql = "UPDATE access_codes SET " . implode(', ', $sets) . " WHERE id=? LIMIT 1";
    $vals[] = $id;
    $upd = $pdo->prepare($sql);
    $upd->execute($vals);

    audit_log_event('code_edit', 'access_codes', $id, [
      'exam_id' => $exam_id,
      'exam_name' => $exam_name,
      'max_uses' => $max_uses,
      'expires_at_utc' => $expires_at,
      'is_active' => $is_active,
      'note' => $note ?: null,
      'user_email' => $user_email ?: null,
      'user_phone' => $user_phone ?: null
    ] + $audit_extra);

    $msg = "Updated.";
    $stmt->execute([$id]);
    $row = $stmt->fetch();
  }
}

$expires_local_value = '';
if (!empty($row['expires_at'])) {
  $utc = new DateTime($row['expires_at'], new DateTimeZone('UTC'));
  $utc->setTimezone(new DateTimeZone(date_default_timezone_get()));
  $expires_local_value = $utc->format('Y-m-d\\TH:i');
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="m-0">Edit Code #<?= (int)$row['id'] ?></h4>
  <a class="btn btn-outline-secondary" href="/admin/">Back</a>
</div>

<?php if ($msg): ?>
  <div class="alert alert-info"><?= h($msg) ?></div>
<?php endif; ?>

<div class="card p-3 shadow-sm" style="max-width: 900px;">
  <div class="mb-2">Code:</div>
  <div class="mono fs-5"><?= !empty($row['code_plain']) ? h($row['code_plain']) : '<span class="text-muted">[hidden]</span>' ?></div>
  <hr>

  <form method="post">
    <?= csrf_input() ?>

    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Dumps Name *</label>
        <input class="form-control" name="exam_name" value="<?= h($row['exam_name'] ?? '') ?>" required>
      </div>

      <div class="col-md-6">
        <label class="form-label">Dumps Code *</label>
        <input class="form-control" name="exam_id" value="<?= h($row['exam_id']) ?>" required>
      </div>

      <div class="col-md-4">
        <label class="form-label">User Email</label>
        <input class="form-control" name="user_email" type="email" value="<?= h($row['user_email'] ?? '') ?>">
      </div>

      <div class="col-md-4">
        <label class="form-label">User Phone</label>
        <input class="form-control" name="user_phone" value="<?= h($row['user_phone'] ?? '') ?>">
      </div>

      <div class="col-md-4">
        <label class="form-label">User Address</label>
        <input class="form-control" name="user_address" value="<?= h($row['user_address'] ?? '') ?>">
      </div>

      <div class="col-12">
        <label class="form-label">R2 File</label>
        <?php
          $curKey = (string)($row['r2_key'] ?? '');
          $curUrl = (string)($row['r2_url'] ?? '');
          if ($curUrl === '' && $curKey !== '') $curUrl = r2_public_url($curKey);
        ?>
        <?php if ($curKey !== ''): ?>
          <div class="mb-2 small">
            <span class="text-muted">Current:</span>
            <?php if ($curUrl): ?>
              <a href="<?= h($curUrl) ?>" target="_blank" rel="noopener" class="mono"><?= h($curKey) ?></a>
            <?php else: ?>
              <span class="mono"><?= h($curKey) ?></span>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="mb-2 small text-muted">No R2 file linked yet.</div>
        <?php endif; ?>

        <div class="row g-2">
          <div class="col-md-6">
            <input class="form-control" name="r2_prefix" value="<?= h($r2_prefix_filter) ?>" placeholder="R2 Prefix (optional) e.g. TTC/AZ-700">
            <div class="text-muted small">Tip: Use prefix to narrow the dropdown if your bucket has many files.</div>
          </div>
          <div class="col-md-6 d-flex align-items-end">
            <a class="btn btn-outline-primary w-100" href="/admin/code_edit.php?id=<?= (int)$row['id'] ?>&r2_prefix=<?= urlencode($r2_prefix_filter) ?>">
              <i class="bi bi-cloud-arrow-down"></i> Load R2 Files
            </a>
          </div>
        </div>

        <?php if ($r2_list_error): ?>
          <div class="alert alert-warning mt-2 mb-0">R2 List Error: <?= h($r2_list_error) ?></div>
        <?php endif; ?>

        <select class="form-select mt-2" name="r2_key">
          <option value="">-- Keep current --</option>
          <?php foreach ($r2_files as $f): ?>
            <option value="<?= h($f['key']) ?>"><?= h($f['key']) ?> (<?= number_format($f['size']/1024, 1) ?> KB)</option>
          <?php endforeach; ?>
        </select>
        <div class="text-muted small">Bucket: <?= h((string)r2_config('R2_BUCKET', '')) ?> • Domain: <?= h((string)r2_config('R2_PUBLIC_BASE', '')) ?></div>
      </div>

      <div class="col-md-3">
        <label class="form-label">Max Uses</label>
        <input class="form-control" type="number" name="max_uses" value="<?= (int)$row['max_uses'] ?>" min="1">
      </div>

      <div class="col-md-3 d-flex align-items-end">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="is_active" <?= ((int)$row['is_active']===1)?'checked':'' ?>>
          <label class="form-check-label">Active</label>
        </div>
      </div>

      <div class="col-md-6">
        <label class="form-label">Expiry (datetime-local)</label>
        <input class="form-control" type="datetime-local" name="expires_local" value="<?= h($expires_local_value) ?>">
      </div>

      <div class="col-12">
        <label class="form-label">Note</label>
        <input class="form-control" name="note" value="<?= h($row['note'] ?? '') ?>">
      </div>
    </div>

    <div class="mt-3">
      <button class="btn btn-primary" type="submit" title="Save">
        <i class="bi bi-save"></i>
      </button>
      <a class="btn btn-outline-warning" href="/admin/code_reset.php?id=<?= (int)$row['id'] ?>" title="Reset Device">
        <i class="bi bi-arrow-counterclockwise"></i>
      </a>
      <a class="btn btn-outline-danger" href="/admin/code_delete.php?id=<?= (int)$row['id'] ?>" title="Delete">
        <i class="bi bi-trash"></i>
      </a>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
