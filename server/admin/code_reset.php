<?php
require_once __DIR__ . '/_header.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/audit.php';

require_permission('code.reset');

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
  $reset_users = isset($_POST['reset_users']) ? 1 : 0;
  $reset_uses  = isset($_POST['reset_uses']) ? 1 : 0;

  $sets = [
    'device_hash=NULL',
    'first_used_at=NULL',
    'last_used_at=NULL',
  ];
  if ($reset_users) $sets[] = 'used_count=0';
  if ($reset_uses)  $sets[] = 'uses_count=0';
  $sets[] = 'updated_by=?';

  $sql = "UPDATE access_codes SET " . implode(', ', $sets) . " WHERE id=? LIMIT 1";
  $upd = $pdo->prepare($sql);
  $upd->execute([(int)$me['id'], $id]);

  audit_log_event('code_reset_device', 'access_codes', $id, [
    'reset_users' => $reset_users,
    'reset_uses' => $reset_uses
  ]);

  if ($reset_users && $reset_uses) $msg = "Device + users + uses reset.";
  else if ($reset_users) $msg = "Device + users reset.";
  else if ($reset_uses) $msg = "Device + uses reset.";
  else $msg = "Device reset.";
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
    <div class="text-muted small">Users: <?= (int)($row['used_count'] ?? 0) ?> / <?= (int)($row['max_users'] ?? 1) ?></div>
    <div class="text-muted small">Uses: <?= (int)($row['uses_count'] ?? 0) ?> / <?= ((int)($row['max_uses'] ?? 0)===0 ? '∞' : (int)$row['max_uses']) ?></div>
  </div>

  <hr>

  <form method="post">
    <?= csrf_input() ?>
    <div class="form-check mb-2">
      <input class="form-check-input" type="checkbox" name="reset_users" id="reset_users">
      <label class="form-check-label" for="reset_users">
        Also reset <span class="mono">users used_count</span> to 0 (use carefully)
      </label>
    </div>
    <div class="form-check mb-3">
      <input class="form-check-input" type="checkbox" name="reset_uses" id="reset_uses">
      <label class="form-check-label" for="reset_uses">
        Also reset <span class="mono">uses_count</span> to 0 (use carefully)
      </label>
    </div>
    <button class="btn btn-warning" type="submit">Reset Device Lock</button>
  </form>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
