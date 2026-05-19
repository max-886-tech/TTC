<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/audit.php';
$me = require_login();
require_permission('users.manage');
$pdo = db(); $msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $username = trim($_POST['username'] ?? '');
  $full_name = trim($_POST['full_name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $phone = trim($_POST['phone'] ?? '');
  $address = trim($_POST['address'] ?? '');
  $password = $_POST['password'] ?? '';
  $role = $_POST['role'] ?? 'admin';
  $is_active = isset($_POST['is_active']) ? 1 : 0;
  if ($username === '' || $password === '') {
    $msg = 'Username and Password required.';
  } else {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    try {
      $legacyRoleToStore = in_array($role, ['admin','viewer'], true) ? $role : 'viewer';
      $cols = "username, password_hash, role, is_active";
      $vals = "?,?,?,?";
      $args = [$username, $hash, $legacyRoleToStore, $is_active];
      if (db_has_column($pdo, 'admin_users', 'full_name')) { $cols .= ", full_name, email, phone, address"; $vals .= ",?,?,?,?"; array_push($args, $full_name ?: null, $email ?: null, $phone ?: null, $address ?: null); }
      $stmt = $pdo->prepare("INSERT INTO admin_users ($cols) VALUES ($vals)");
      $stmt->execute($args);
      $newId = (int)$pdo->lastInsertId();
      if (rbac_enabled($pdo)) {
        $st = $pdo->prepare("SELECT id FROM roles WHERE name=? LIMIT 1"); $st->execute([$role]); $rid = (int)($st->fetchColumn() ?: 0);
        if ($rid > 0) $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?,?)")->execute([$newId, $rid]);
      }
      audit_log_event('user_create', 'admin_users', $newId, ['username' => $username, 'role' => $role, 'is_active' => $is_active]);
      header('Location: /admin/users.php'); exit;
    } catch (Throwable $e) { $msg = 'Username already exists (or DB error).'; }
  }
}
$roles = rbac_list_roles($pdo);
require_once __DIR__ . '/_header.php';
?>
<div class="admin-page-header"><div><h1 class="admin-page-title">Add User</h1><div class="admin-page-subtitle">Create a user profile and assign its role.</div></div><a class="btn btn-outline-secondary" href="/admin/users.php"><i class="bi bi-arrow-left me-1"></i> Back</a></div>
<?php if ($msg): ?><div class="alert alert-danger"><?= h($msg) ?></div><?php endif; ?>
<div class="admin-panel" style="max-width: 980px;">
  <div class="admin-panel-header"><div><div class="admin-section-title">New User Profile</div><div class="admin-section-subtitle">Includes login and contact details for code assignment.</div></div><span class="wp-pill wp-pill-blue"><i class="bi bi-person-plus"></i> Create</span></div>
  <div class="admin-panel-body">
    <form method="post"><?= csrf_input() ?>
      <div class="admin-form-grid">
        <div class="admin-col-6"><label class="form-label">Username</label><input class="form-control" name="username" value="<?= h((string)($_POST['username'] ?? '')) ?>" required></div>
        <div class="admin-col-6"><label class="form-label">Full Name</label><input class="form-control" name="full_name" value="<?= h((string)($_POST['full_name'] ?? '')) ?>"></div>
        <div class="admin-col-6"><label class="form-label">Email</label><input class="form-control" type="email" name="email" value="<?= h((string)($_POST['email'] ?? '')) ?>"></div>
        <div class="admin-col-6"><label class="form-label">Phone</label><input class="form-control" name="phone" value="<?= h((string)($_POST['phone'] ?? '')) ?>"></div>
        <div class="admin-col-6"><label class="form-label">Address</label><input class="form-control" name="address" value="<?= h((string)($_POST['address'] ?? '')) ?>"></div>
        <div class="admin-col-6"><label class="form-label">Password</label><input class="form-control" name="password" type="password" required></div>
        <div class="admin-col-6"><label class="form-label">Role</label><select class="form-select" name="role"><?php foreach ($roles as $r): $name = is_array($r) ? ($r['name'] ?? '') : (string)$r; ?><option value="<?= h($name) ?>" <?= $name==='attempt_exam'?'selected':'' ?>><?= h($name) ?></option><?php endforeach; ?></select><div class="text-muted small mt-2">Use <span class="mono">attempt_exam</span> for dumps-only users.</div></div>
        <div class="admin-col-6 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_active" checked id="is_active"><label class="form-check-label" for="is_active">User is active immediately</label></div></div>
      </div>
      <div class="d-flex flex-wrap gap-2 mt-4"><button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle me-1"></i> Create User</button><a class="btn btn-outline-secondary" href="/admin/users.php">Cancel</a></div>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/_footer.php'; ?>
