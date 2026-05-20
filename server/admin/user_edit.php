<?php
require_once __DIR__ . '/_header.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/audit.php';

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { echo "Missing id"; exit; }

$stmt = $pdo->prepare("SELECT id, username, role, is_active, last_login_at, created_at FROM admin_users WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) { echo "Not found"; exit; }

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $role = $_POST['role'] ?? $row['role'];
  $is_active = isset($_POST['is_active']) ? 1 : 0;
  $new_pass = $_POST['new_password'] ?? '';

  if ($id === (int)$me['id'] && $is_active === 0) {
    $msg = "You cannot disable yourself.";
  } else {
    $pdo->prepare("UPDATE admin_users SET role=?, is_active=? WHERE id=?")->execute([$role, $is_active, $id]);

    if (trim($new_pass) !== '') {
      $hash = password_hash($new_pass, PASSWORD_DEFAULT);
      $pdo->prepare("UPDATE admin_users SET password_hash=? WHERE id=?")->execute([$hash, $id]);
      audit_log_event('user_password_reset', 'admin_users', $id, []);
    }

    audit_log_event('user_edit', 'admin_users', $id, ['role' => $role, 'is_active' => $is_active]);

    $msg = "Saved.";
    $stmt->execute([$id]);
    $row = $stmt->fetch();
  }
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="m-0">Edit User #<?= (int)$row['id'] ?></h4>
  <a class="btn btn-outline-secondary" href="/admin/users.php">Back</a>
</div>

<?php if ($msg): ?>
  <div class="alert alert-info"><?= h($msg) ?></div>
<?php endif; ?>

<form method="post" class="card p-3 shadow-sm" style="max-width: 620px;">
  <?= csrf_input() ?>

  <div class="mb-2 text-muted small">Username: <b><?= h($row['username']) ?></b></div>
  <div class="mb-3 text-muted small">Last Login: <span class="mono"><?= h($row['last_login_at'] ?? '') ?></span></div>

  <div class="mb-3">
    <label class="form-label">Role</label>
    <select class="form-select" name="role">
      <option value="admin" <?= $row['role']==='admin'?'selected':'' ?>>admin</option>
      <option value="viewer" <?= $row['role']==='viewer'?'selected':'' ?>>viewer</option>
    </select>
  </div>

  <div class="form-check mb-3">
    <input class="form-check-input" type="checkbox" name="is_active" <?= ((int)$row['is_active']===1)?'checked':'' ?>>
    <label class="form-check-label">Active</label>
  </div>

  <hr>

  <div class="mb-3">
    <label class="form-label">Reset Password (optional)</label>
    <input class="form-control" name="new_password" type="password" placeholder="Leave empty to keep unchanged">
  </div>

  <button class="btn btn-primary" type="submit">Save</button>
</form>

<?php require_once __DIR__ . '/_footer.php'; ?>
