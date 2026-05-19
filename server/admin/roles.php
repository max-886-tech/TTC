<?php
require_once __DIR__ . '/_header.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/rbac.php';
require_once __DIR__ . '/../lib/audit.php';

require_permission('roles.manage');

$pdo = db();
$msg = '';

if (!rbac_enabled($pdo)) {
  ?>
  <div class="admin-page-header">
    <div>
      <h1 class="admin-page-title">User Roles & Permissions</h1>
      <div class="admin-page-subtitle">Create user roles, assign capabilities and manage access policy.</div>
    </div>
  </div>
  <div class="alert alert-warning">
    RBAC tables are not installed yet. <a href="/admin/rbac_install.php" class="alert-link">Install RBAC</a> to enable roles & permissions.
  </div>
  <?php
  require_once __DIR__ . '/_footer.php';
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? '';

  if ($action === 'create') {
    $name = strtolower(trim($_POST['name'] ?? ''));
    $desc = trim($_POST['description'] ?? '');
    if ($name === '') {
      $msg = 'Role name required.';
    } else {
      try {
        $pdo->prepare("INSERT INTO roles (name, description) VALUES (?,?)")->execute([$name, $desc]);
        audit_log_event('role_create', 'roles', (int)$pdo->lastInsertId(), ['name' => $name]);
        $msg = 'Role created.';
      } catch (Throwable $e) {
        $msg = 'Could not create role (duplicate name?).';
      }
    }
  }

  if ($action === 'save') {
    $rid = (int)($_POST['role_id'] ?? 0);
    $desc = trim($_POST['description'] ?? '');
    $perms = $_POST['perms'] ?? [];
    if (!is_array($perms)) $perms = [];

    $st = $pdo->prepare("SELECT id, name FROM roles WHERE id=? LIMIT 1");
    $st->execute([$rid]);
    $role = $st->fetch();
    if (!$role) {
      $msg = 'Role not found.';
    } else {
      $pdo->beginTransaction();
      try {
        $pdo->prepare("UPDATE roles SET description=? WHERE id=?")->execute([$desc, $rid]);
        $pdo->prepare("DELETE FROM role_permissions WHERE role_id=?")->execute([$rid]);
        $permIds = [];
        foreach ($pdo->query("SELECT id, code FROM permissions")->fetchAll() as $p) {
          $permIds[$p['code']] = (int)$p['id'];
        }
        $ins = $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?,?)");
        foreach ($perms as $code) {
          $code = trim((string)$code);
          if ($code === '' || !isset($permIds[$code])) continue;
          $ins->execute([$rid, $permIds[$code]]);
        }
        $pdo->commit();
        audit_log_event('role_update', 'roles', $rid, ['perms' => array_values($perms)]);
        $msg = 'Saved.';
      } catch (Throwable $e) {
        $pdo->rollBack();
        $msg = 'Save failed: ' . $e->getMessage();
      }
    }
  }

  if ($action === 'delete') {
    $rid = (int)($_POST['role_id'] ?? 0);
    $st = $pdo->prepare("SELECT name FROM roles WHERE id=? LIMIT 1");
    $st->execute([$rid]);
    $name = (string)($st->fetchColumn() ?: '');
    if ($name === 'admin') {
      $msg = 'You cannot delete the admin role.';
    } else {
      $pdo->prepare("DELETE FROM roles WHERE id=?")->execute([$rid]);
      audit_log_event('role_delete', 'roles', $rid, []);
      $msg = 'Role deleted.';
    }
  }
}


// Keep admin role aligned with all permission codes so newly added permissions appear selected.
$adminRoleId = (int)($pdo->query("SELECT id FROM roles WHERE name='admin' LIMIT 1")->fetchColumn() ?: 0);
if ($adminRoleId > 0) {
  try {
    $pdo->exec("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT {$adminRoleId}, p.id FROM permissions p");
  } catch (Throwable $e) {}
}

$roles = $pdo->query("SELECT id, name, description FROM roles ORDER BY name")->fetchAll();
$selectedId = (int)($_GET['id'] ?? ($roles[0]['id'] ?? 0));

$sel = null;
if ($selectedId > 0) {
  $st = $pdo->prepare("SELECT id, name, description FROM roles WHERE id=? LIMIT 1");
  $st->execute([$selectedId]);
  $sel = $st->fetch();
}

$selPerms = [];
if ($sel) {
  $st = $pdo->prepare("SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id=?");
  $st->execute([(int)$sel['id']]);
  $selPerms = array_values(array_map(fn($x) => $x['code'], $st->fetchAll()));
}

$allPerms = $pdo->query("SELECT code, label FROM permissions ORDER BY code")->fetchAll();
$roleCounts = [];
foreach ($pdo->query("SELECT role_id, COUNT(*) AS cnt FROM user_roles GROUP BY role_id")->fetchAll() as $rc) {
  $roleCounts[(int)$rc['role_id']] = (int)$rc['cnt'];
}
$permCountByRole = [];
foreach ($pdo->query("SELECT role_id, COUNT(*) AS cnt FROM role_permissions GROUP BY role_id")->fetchAll() as $pc) {
  $permCountByRole[(int)$pc['role_id']] = (int)$pc['cnt'];
}
?>

<div class="admin-page-header">
  <div>
    <h1 class="admin-page-title">User Roles & Permissions</h1>
    <div class="admin-page-subtitle">Create user roles, assign capabilities and manage access policy.</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="/admin/users.php"><i class="bi bi-people me-1"></i> Users</a>
    <a class="btn btn-outline-secondary" href="/admin/"><i class="bi bi-arrow-left me-1"></i> Back</a>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="admin-kpi"><div class="admin-kpi-label">Total Roles</div><div class="admin-kpi-value"><?= count($roles) ?></div></div></div>
  <div class="col-md-4"><div class="admin-kpi"><div class="admin-kpi-label">Permission Codes</div><div class="admin-kpi-value"><?= count($allPerms) ?></div></div></div>
  <div class="col-md-4"><div class="admin-kpi"><div class="admin-kpi-label">Selected Role Users</div><div class="admin-kpi-value"><?= (int)($roleCounts[(int)($sel['id'] ?? 0)] ?? 0) ?></div></div></div>
</div>

<?php if ($msg): ?>
  <div class="alert alert-info"><?= h($msg) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-xl-4">
    <div class="admin-panel overflow-hidden">
      <div class="admin-panel-header">
        <div>
          <div class="admin-section-title">Role Directory</div>
          <div class="admin-section-subtitle">Quick access to each permission profile.</div>
        </div>
        <span class="wp-pill wp-pill-blue"><i class="bi bi-shield-lock"></i> <?= count($roles) ?> roles</span>
      </div>
      <div class="list-group list-group-flush admin-list-tight">
        <?php foreach ($roles as $r): ?>
          <?php $rid = (int)$r['id']; ?>
          <a class="list-group-item list-group-item-action <?= ($rid === $selectedId) ? 'active' : '' ?>" href="/admin/roles.php?id=<?= $rid ?>">
            <div class="d-flex align-items-start justify-content-between gap-2">
              <div class="min-w-0">
                <div class="fw-semibold text-truncate"><i class="bi bi-person-badge me-1"></i> <?= h($r['name']) ?></div>
                <div class="small opacity-75 text-truncate"><?= h($r['description'] ?: 'No description yet.') ?></div>
              </div>
              <span class="badge text-bg-light border">#<?= $rid ?></span>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-2 small">
              <span class="wp-pill <?= $rid === $selectedId ? 'wp-pill-gray' : 'wp-pill-blue' ?>"><i class="bi bi-key"></i> <?= (int)($permCountByRole[$rid] ?? 0) ?> perms</span>
              <span class="wp-pill <?= $rid === $selectedId ? 'wp-pill-gray' : 'wp-pill-amber' ?>"><i class="bi bi-people"></i> <?= (int)($roleCounts[$rid] ?? 0) ?> users</span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="admin-panel-body border-top">
        <div class="admin-section-title mb-1">Create New Role</div>
        <div class="admin-section-subtitle mb-3">Use lowercase names like staff, viewer, or agent.</div>
        <form method="post" class="vstack gap-2">
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="create">
          <input class="form-control" name="name" placeholder="new role name (e.g., staff)" required>
          <input class="form-control" name="description" placeholder="description (optional)">
          <button class="btn btn-primary" type="submit"><i class="bi bi-plus-circle me-1"></i> Add Role</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-xl-8">
    <?php if (!$sel): ?>
      <div class="alert alert-warning">Select a role to edit.</div>
    <?php else: ?>
      <div class="admin-panel overflow-hidden">
        <div class="admin-panel-header">
          <div>
            <div class="admin-section-title d-flex align-items-center gap-2">
              <span class="admin-icon-badge"><i class="bi bi-shield-check"></i></span>
              <span>Edit: <?= h($sel['name']) ?></span>
            </div>
            <div class="admin-section-subtitle">Fine-tune description and permission access for this role.</div>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <span class="wp-pill wp-pill-blue"><i class="bi bi-key"></i> <?= (int)($permCountByRole[(int)$sel['id']] ?? 0) ?> permissions</span>
            <span class="wp-pill wp-pill-amber"><i class="bi bi-people"></i> <?= (int)($roleCounts[(int)$sel['id']] ?? 0) ?> users</span>
            <form method="post" class="m-0" onsubmit="return confirm('Delete this role?');">
              <?= csrf_input() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="role_id" value="<?= (int)$sel['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit" ><i class="bi bi-trash me-1"></i> Delete</button>
            </form>
          </div>
        </div>
        <div class="admin-panel-body">
          <form method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="role_id" value="<?= (int)$sel['id'] ?>">

            <div class="mb-4">
              <label class="form-label">Description</label>
              <input class="form-control" name="description" value="<?= h($sel['description'] ?? '') ?>" placeholder="What this role is meant to do">
            </div>

            <div class="admin-toolbar">
              <div>
                <div class="admin-section-title">Permissions</div>
                <div class="admin-section-subtitle">Toggle individual capabilities for this role.</div>
              </div>
              <span class="wp-pill wp-pill-blue"><i class="bi bi-list-check"></i> <?= count($allPerms) ?> available</span>
            </div>
            <div class="row g-2">
              <?php foreach ($allPerms as $p): ?>
                <?php $code = (string)$p['code']; $checked = in_array($code, $selPerms, true); ?>
                <div class="col-md-4">
                  <label class="d-flex align-items-start gap-3 h-100 bg-white w-100 <?= $checked ? 'border-primary-subtle' : '' ?>">
                    <input class="form-check-input mt-1" type="checkbox" name="perms[]" value="<?= h($code) ?>" <?= $checked?'checked':'' ?> >
                    <span class="flex-grow-1">
                      <span class="d-flex align-items-center gap-2 mb-1">
                        <span class="mono fw-semibold"><?= h($code) ?></span>
                      </span>
                 
                    </span>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="mt-4 d-flex gap-2">
              <button class="btn btn-primary" type="submit" ><i class="bi bi-save me-1"></i> Save Changes</button>
              <a class="btn btn-outline-secondary" href="/admin/roles.php?id=<?= (int)$sel['id'] ?>"><i class="bi bi-arrow-clockwise me-1"></i> Reset View</a>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
