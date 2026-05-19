<?php
require_once __DIR__ . '/_header.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/audit.php';
require_permission('users.manage');
$pdo = db();
$id = (int)($_GET['id'] ?? 0); if ($id <= 0) { echo 'Missing id'; exit; }
$select = "SELECT id, username, role, is_active, last_login_at, created_at" . (db_has_column($pdo,'admin_users','full_name') ? ", full_name, email, phone, address" : "") . " FROM admin_users WHERE id=? LIMIT 1";
$stmt = $pdo->prepare($select); $stmt->execute([$id]); $row = $stmt->fetch(); if (!$row) { echo 'Not found'; exit; }
$legacyRoles = ['admin', 'viewer'];
$getDisplayRole = function(PDO $pdo, array $row) use ($legacyRoles): string {
  if (rbac_enabled($pdo) && db_has_table($pdo, 'roles') && db_has_table($pdo, 'user_roles')) {
    $st = $pdo->prepare("SELECT r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.name ASC LIMIT 1");
    $st->execute([(int)$row['id']]);
    $roleName = trim((string)($st->fetchColumn() ?: ''));
    if ($roleName !== '') return $roleName;
  }
  return (string)($row['role'] ?? 'viewer');
};
$currentDisplayRole = $getDisplayRole($pdo, $row);
$msg='';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $role = trim((string)($_POST['role'] ?? $currentDisplayRole));
  $is_active = isset($_POST['is_active']) ? 1 : 0;
  $new_pass = $_POST['new_password'] ?? '';
  $full_name = trim($_POST['full_name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $phone = trim($_POST['phone'] ?? '');
  $address = trim($_POST['address'] ?? '');
  if ($id === (int)$me['id'] && $is_active === 0) {
    $msg = 'You cannot disable yourself.';
  } else {
    $legacyRoleToStore = in_array($role, $legacyRoles, true) ? $role : 'viewer';
    $sql = "UPDATE admin_users SET role=?, is_active=?";
    $args = [$legacyRoleToStore, $is_active];
    if (db_has_column($pdo, 'admin_users', 'full_name')) { $sql .= ", full_name=?, email=?, phone=?, address=?"; array_push($args, $full_name ?: null, $email ?: null, $phone ?: null, $address ?: null); }
    $sql .= " WHERE id=?"; $args[] = $id; $pdo->prepare($sql)->execute($args);
    if (rbac_enabled($pdo)) {
      $st = $pdo->prepare("SELECT id FROM roles WHERE name=? LIMIT 1"); $st->execute([$role]); $rid = (int)($st->fetchColumn() ?: 0);
      if ($rid > 0) { $pdo->prepare("DELETE FROM user_roles WHERE user_id=?")->execute([$id]); $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?,?)")->execute([$id, $rid]); }
    }
    if (trim($new_pass) !== '') { $hash = password_hash($new_pass, PASSWORD_DEFAULT); $pdo->prepare("UPDATE admin_users SET password_hash=? WHERE id=?")->execute([$hash, $id]); audit_log_event('user_password_reset', 'admin_users', $id, []); }
    audit_log_event('user_edit', 'admin_users', $id, ['role' => $role, 'is_active' => $is_active]);
    $msg='Saved.'; $stmt->execute([$id]); $row=$stmt->fetch(); $currentDisplayRole = $getDisplayRole($pdo, $row);
  }
}
$roles = rbac_list_roles($pdo); $isSelf = $id === (int)$me['id'];
?>
<div class="admin-page-header"><div><h1 class="admin-page-title">Edit User #<?= (int)$row['id'] ?></h1><div class="admin-page-subtitle">Update role, profile details and password for <?= h($row['username']) ?>.</div></div><a class="btn btn-outline-secondary" href="/admin/users.php"><i class="bi bi-arrow-left me-1"></i> Back</a></div>
<?php if ($msg): ?><div class="alert alert-info"><?= h($msg) ?></div><?php endif; ?>
<div class="admin-panel" style="max-width: 980px;">
  <div class="admin-panel-header"><div class="d-flex align-items-center gap-3"><span class="admin-icon-badge" style="width:40px;height:40px;"><i class="bi bi-person-gear"></i></span><div><div class="admin-section-title"><?= h(trim((string)($row['full_name'] ?? '')) ?: $row['username']) ?></div><div class="admin-section-subtitle">Created: <span class="mono"><?= h($row['created_at'] ?: '—') ?></span> · Last Login: <span class="mono"><?= h($row['last_login_at'] ?: '—') ?></span></div></div></div><div class="d-flex flex-wrap gap-2"><span class="wp-pill wp-pill-blue"><i class="bi bi-person-badge"></i> <?= h($currentDisplayRole) ?></span><?php if ((int)$row['is_active']): ?><span class="wp-pill wp-pill-green"><i class="bi bi-check-circle"></i> Active</span><?php else: ?><span class="wp-pill wp-pill-gray"><i class="bi bi-pause-circle"></i> Disabled</span><?php endif; ?><?php if ($isSelf): ?><span class="wp-pill wp-pill-amber"><i class="bi bi-star"></i> You</span><?php endif; ?></div></div>
  <div class="admin-panel-body"><form method="post"><?= csrf_input() ?>
    <div class="admin-form-grid">
      <div class="admin-col-6"><label class="form-label">Username</label><input class="form-control" value="<?= h($row['username']) ?>" disabled><div class="text-muted small mt-2">Username is kept stable for login and audit references.</div></div>
      <div class="admin-col-6"><label class="form-label">Full Name</label><input class="form-control" name="full_name" value="<?= h((string)($row['full_name'] ?? '')) ?>"></div>
      <div class="admin-col-6"><label class="form-label">Email</label><input class="form-control" type="email" name="email" value="<?= h((string)($row['email'] ?? '')) ?>"></div>
      <div class="admin-col-6"><label class="form-label">Phone</label><input class="form-control" name="phone" value="<?= h((string)($row['phone'] ?? '')) ?>"></div>
      <div class="admin-col-6"><label class="form-label">Address</label><input class="form-control" name="address" value="<?= h((string)($row['address'] ?? '')) ?>"></div>
      <div class="admin-col-6"><label class="form-label">Role</label><select class="form-select" name="role"><?php foreach ($roles as $r): $name = is_array($r) ? ($r['name'] ?? '') : (string)$r; ?><option value="<?= h($name) ?>" <?= ($currentDisplayRole === $name)?'selected':'' ?>><?= h($name) ?></option><?php endforeach; ?></select></div>
      <div class="admin-col-6 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?= ((int)$row['is_active']===1)?'checked':'' ?>><label class="form-check-label" for="is_active">Account is active</label></div></div>
      <div class="admin-col-6"><label class="form-label">Reset Password</label><input class="form-control" name="new_password" type="password" placeholder="Leave empty to keep unchanged"></div>
    </div>
    <?php if ($isSelf): ?><div class="alert alert-light border mt-3 mb-0">This is your current account. The backend already blocks disabling yourself.</div><?php endif; ?>
    <div class="d-flex flex-wrap gap-2 mt-4"><button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i> Save Changes</button><a class="btn btn-outline-secondary" href="/admin/users.php">Cancel</a></div>
  </form></div>
</div>
<?php require_once __DIR__ . '/_footer.php'; ?>
