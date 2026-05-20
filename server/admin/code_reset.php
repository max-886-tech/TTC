<?php
require_once __DIR__ . '/_header.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/audit.php';

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { echo "Missing id"; exit; }

$stmt = $pdo->prepare("SELECT * FROM access_codes WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) { echo "Not found"; exit; }

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $reset_count = isset($_POST['reset_count']) ? 1 : 0;

  if ($reset_count) {
    $upd = $pdo->prepare("
      UPDATE access_codes
      SET device_hash=NULL, first_used_at=NULL, last_used_at=NULL, used_count=0, updated_by=?
      WHERE id=? LIMIT 1
    ");
    $upd->execute([(int)$me['id'], $id]);
  } else {
    $upd = $pdo->prepare("
      UPDATE access_codes
      SET device_hash=NULL, first_used_at=NULL, last_used_at=NULL, updated_by=?
      WHERE id=? LIMIT 1
    ");
    $upd->execute([(int)$me['id'], $id]);
  }

  audit_log_event('code_reset_device', 'access_codes', $id, [
    'reset_count' => $reset_count
  ]);

  $msg = $reset_count ? "Device + used_count reset." : "Device reset.";
  $stmt->execute([$id]);
  $row = $stmt->fetch();
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="m-0">Reset Device</h4>
  <a class="btn btn-outline-secondary" href="/admin/code_edit.php?id=<?= (int)$row['id'] ?>">Back</a>
</div>

<?php if ($msg): ?>
  <div class="alert alert-success"><?= h($msg) ?></div>
<?php endif; ?>

<div class="card p-3 shadow-sm" style="max-width: 720px;">
  <div class="mb-2">Code:</div>
  <div class="mono fs-5"><?= !empty($row['code_plain']) ? h($row['code_plain']) : '<span class="text-muted">[hidden]</span>' ?></div>

  <div class="mt-3">
    <div class="text-muted small">Current device: <?= !empty($row['device_hash']) ? '<span class="mono">'.h(substr($row['device_hash'],0,16)).'…</span>' : '<span class="text-muted">none</span>' ?></div>
    <div class="text-muted small">Used count: <?= (int)$row['used_count'] ?> / <?= (int)$row['max_uses'] ?></div>
  </div>

  <hr>

  <form method="post">
    <?= csrf_input() ?>
    <div class="form-check mb-3">
      <input class="form-check-input" type="checkbox" name="reset_count" id="reset_count">
      <label class="form-check-label" for="reset_count">
        Also reset <span class="mono">used_count</span> to 0 (use carefully)
      </label>
    </div>
    <button class="btn btn-warning" type="submit">Reset Device Lock</button>
  </form>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
