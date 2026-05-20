<?php
require_once __DIR__ . '/_header.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/audit.php';

$pdo = db();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';
  $role = $_POST['role'] ?? 'admin';
  $is_active = isset($_POST['is_active']) ? 1 : 0;

  if ($username === '' || $password === '') {
    $msg = "Username and Password required.";
  } else {
    $hash = password_hash($password, PASSWORD_DEFAULT);

    try {
      $stmt = $pdo->prepare("INSERT INTO admin_users (username, password_hash, role, is_active) VALUES (?,?,?,?)");
      $stmt->execute([$username, $hash, $role, $is_active]);
      $newId = (int)$pdo->lastInsertId();

      audit_log_event('user_create', 'admin_users', $newId, ['username' => $username, 'role' => $role, 'is_active' => $is_active]);

      header("Location: /admin/users.php");
      exit;
    } catch (Throwable $e) {
      $msg = "Username already exists (or DB error).";
    }
  }
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="m-0">Add User</h4>
  <a class="btn btn-outline-secondary" href="/admin/users.php">Back</a>
</div>

<?php if ($msg): ?>
  <div class="alert alert-danger"><?= h($msg) ?></div>
<?php endif; ?>

<form method="post" class="card p-3 shadow-sm" style="max-width: 520px;">
  <?= csrf_input() ?>

  <div class="mb-3">
    <label class="form-label">Username</label>
    <input class="form-control" name="username" required>
  </div>

  <div class="mb-3">
    <label class="form-label">Password</label>
    <input class="form-control" name="password" type="password" required>
  </div>

  <div class="mb-3">
    <label class="form-label">Role</label>
    <select class="form-select" name="role">
      <option value="admin" selected>admin</option>
      <option value="viewer">viewer</option>
    </select>
    <div class="text-muted small">viewer can login but (currently) admin pages still require admin. Tell me if you want viewer read-only access.</div>
  </div>

  <div class="form-check mb-3">
    <input class="form-check-input" type="checkbox" name="is_active" checked>
    <label class="form-check-label">Active</label>
  </div>

  <button class="btn btn-primary" type="submit">Create</button>
</form>

<?php require_once __DIR__ . '/_footer.php'; ?>
