<?php
require_once APP_ROOT . '/bootstrap.php';

$pdo = db();
$codesRepo = new \App\Repositories\AccessCodeRepository($pdo);
$codesSvc  = new \App\Services\AccessCodeService();
require_once APP_ROOT . '/admin/_header.php';
require_permission('code.edit');
$pdo = db();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { echo "Missing id"; exit; }

$stmt = $pdo->prepare("SELECT * FROM access_codes WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) { echo "Not found"; exit; }

// Fetch fixed R2 file from Exam (Option A: exam-level file)
$exam_r2_key = '';
$exam_r2_url = '';
try {
  $ex = $pdo->prepare("SELECT r2_key, r2_url FROM exams WHERE exam_id=? LIMIT 1");
  $ex->execute([(string)$row['exam_id']]);
  $er = $ex->fetch();
  if ($er) {
    $exam_r2_key = (string)($er['r2_key'] ?? '');
    $exam_r2_url = (string)($er['r2_url'] ?? '');
  }
} catch (Throwable $e) {
  // ignore if exams table doesn't exist
}


$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $exam_name = trim($_POST['exam_name'] ?? '');
  $exam_id   = trim($_POST['exam_id'] ?? '');

  $user_email   = trim($_POST['user_email'] ?? '');
  $user_phone   = trim($_POST['user_phone'] ?? '');
  $user_address = trim($_POST['user_address'] ?? '');

  $max_users = (int)($_POST['max_users'] ?? 1);
  $max_uses  = (int)($_POST['max_uses'] ?? 0);
  $expires_local = $_POST['expires_local'] ?? '';
  $note = trim($_POST['note'] ?? '');
  $is_active = isset($_POST['is_active']) ? 1 : 0;

  if ($exam_name === '' || $exam_id === '') {
    $msg = "Exam Name and Exam Code are required.";
  } else {
    if ($max_users <= 0) $max_users = 1;
    if ($max_uses < 0) $max_uses = 0;
    $expires_at = parse_datetime_local_to_utc($expires_local);

    // Build data for update (Phase 2: repository)
    $data = [
      'exam_name'    => $exam_name,
      'exam_id'      => $exam_id,
      'user_email'   => $user_email ?: null,
      'user_phone'   => $user_phone ?: null,
      'user_address' => $user_address ?: null,
      'expires_at'   => $expires_at,
      'is_active'    => $is_active,
      'note'         => $note ?: null,
      'updated_by'   => (int)$me['id'],
    ];

    // Limits: prefer new columns; fallback to legacy `max_uses` (historically meant max activations)
    if (db_has_column($pdo, 'access_codes', 'max_users')) {
      $data['max_users'] = $max_users;
    } else {
      $data['max_uses'] = $max_users;
    }

    if (db_has_column($pdo, 'access_codes', 'max_uses')) {
      $data['max_uses'] = $max_uses;
    }

    $codesRepo->update($id, $data);
audit_log_event('code_edit', 'access_codes', $id, [
      'exam_id' => $exam_id,
      'exam_name' => $exam_name,
      'max_users' => $max_users,
      'max_uses'  => $max_uses,
      'expires_at_utc' => $expires_at,
      'is_active' => $is_active,
      'note' => $note ?: null,
      'user_email' => $user_email ?: null,
      'user_phone' => $user_phone ?: null
    ]);

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
        <label class="form-label">Exam Name *</label>
        <input class="form-control" name="exam_name" value="<?= h($row['exam_name'] ?? '') ?>" required>
      </div>

      <div class="col-md-6">
        <label class="form-label">Exam Code *</label>
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
        <label class="form-label">Exam R2 File (fixed)</label>
        <?php if ($exam_r2_key !== ''): ?>
          <div class="small">
            <span class="text-muted">Linked in Exam:</span>
            <?php if ($exam_r2_url !== ''): ?>
              <a href="<?= h($exam_r2_url) ?>" target="_blank" rel="noopener" class="mono"><?= h($exam_r2_key) ?></a>
            <?php else: ?>
              <span class="mono"><?= h($exam_r2_key) ?></span>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="small text-warning">No R2 file linked to this Exam. Go to Admin → New Exam / Edit Exam and select the R2 file.</div>
        <?php endif; ?>
      </div>

      <div class="col-md-3">
        <label class="form-label">Max Users</label>
        <input class="form-control" type="number" name="max_users" value="<?= (int)($row['max_users'] ?? 1) ?>" min="1">
        <div class="text-muted small">Unique users/devices allowed.</div>
        <div class="text-muted small">Used: <?= (int)($row['used_count'] ?? 0) ?> / <?= (int)($row['max_users'] ?? 1) ?></div>
      </div>

      <div class="col-md-3">
        <label class="form-label">Max Uses</label>
        <input class="form-control" type="number" name="max_uses" value="<?= (int)($row['max_uses'] ?? 0) ?>" min="0">
        <div class="text-muted small">Total successful uses (0 = unlimited).</div>
        <div class="text-muted small">Used: <?= (int)($row['uses_count'] ?? 0) ?> / <?= ((int)($row['max_uses'] ?? 0)===0 ? '∞' : (int)$row['max_uses']) ?></div>
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

<?php require_once APP_ROOT . '/admin/_footer.php'; ?>
