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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  if (rbac_enabled($pdo)) {
    // RBAC already installed — just sync/seed any newly added permissions.
    $pdo->beginTransaction();
    try {
      $insPerm = $pdo->prepare("INSERT IGNORE INTO permissions (code, label) VALUES (?,?)");
      foreach (rbac_all_permissions() as $code) {
        $label = $code;
        $insPerm->execute([$code, $label]);
      }

      // Ensure admin role has all permissions (including newly added ones)
      $adminRoleId = (int)($pdo->query("SELECT id FROM roles WHERE name='admin' LIMIT 1")->fetchColumn() ?: 0);
      if ($adminRoleId > 0) {
        $permIds = [];
        $stmt = $pdo->query("SELECT id, code FROM permissions");
        foreach ($stmt->fetchAll() as $p) $permIds[$p['code']] = (int)$p['id'];
        $insRP = $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?,?)");
        foreach (rbac_all_permissions() as $pc) {
          if (!isset($permIds[$pc])) continue;
          $insRP->execute([$adminRoleId, $permIds[$pc]]);
        }
      }

      $pdo->commit();
      try { audit_log_event('rbac_sync_permissions', 'permissions', 0, []); } catch (Throwable $ignored) {}
      $msg = 'RBAC is already installed. Permissions synced.';
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $msg = 'RBAC sync failed: ' . $e->getMessage();
    }
  } else {
    $pdo->beginTransaction();
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS roles (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(64) NOT NULL,
        description VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_roles_name (name)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

      $pdo->exec("CREATE TABLE IF NOT EXISTS permissions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        code VARCHAR(96) NOT NULL,
        label VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_permissions_code (code)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

      $pdo->exec("CREATE TABLE IF NOT EXISTS user_roles (
        user_id INT UNSIGNED NOT NULL,
        role_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (user_id, role_id),
        CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
        CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

      $pdo->exec("CREATE TABLE IF NOT EXISTS role_permissions (
        role_id INT UNSIGNED NOT NULL,
        permission_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (role_id, permission_id),
        CONSTRAINT fk_role_perms_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
        CONSTRAINT fk_role_perms_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

      // Seed permissions
      $insPerm = $pdo->prepare("INSERT IGNORE INTO permissions (code, label) VALUES (?,?)");
      foreach (rbac_all_permissions() as $code) {
        $label = $code;
        $insPerm->execute([$code, $label]);
      }

      // Ensure roles for any legacy values
      $legacyRoles = $pdo->query("SELECT DISTINCT role FROM admin_users")->fetchAll();
      $insRole = $pdo->prepare("INSERT IGNORE INTO roles (name, description) VALUES (?,?)");
      foreach ($legacyRoles as $lr) {
        $name = strtolower(trim((string)($lr['role'] ?? 'viewer')));
        if ($name === '') $name = 'viewer';
        $desc = ($name === 'admin') ? 'Full access' : 'Limited access';
        $insRole->execute([$name, $desc]);
      }

      // Map users -> roles
      $users = $pdo->query("SELECT id, role FROM admin_users")->fetchAll();
      $getRoleId = $pdo->prepare("SELECT id FROM roles WHERE name=? LIMIT 1");
      $insUserRole = $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?,?)");
      foreach ($users as $u) {
        $name = strtolower(trim((string)($u['role'] ?? 'viewer')));
        if ($name === '') $name = 'viewer';
        $getRoleId->execute([$name]);
        $rid = (int)($getRoleId->fetchColumn() ?: 0);
        if ($rid > 0) $insUserRole->execute([(int)$u['id'], $rid]);
      }

      // Assign default permissions per role
      $permIds = [];
      $stmt = $pdo->query("SELECT id, code FROM permissions");
      foreach ($stmt->fetchAll() as $p) $permIds[$p['code']] = (int)$p['id'];

      $roles = $pdo->query("SELECT id, name FROM roles")->fetchAll();
      $insRP = $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?,?)");
      foreach ($roles as $r) {
        $name = (string)$r['name'];
        $rperms = ($name === 'admin') ? rbac_all_permissions() : ['codes.view'];
        foreach ($rperms as $pc) {
          if (!isset($permIds[$pc])) continue;
          $insRP->execute([(int)$r['id'], $permIds[$pc]]);
        }
      }

      $pdo->commit();
      // Log outside the transaction (do not fail install if audit logging fails)
      try { audit_log_event('rbac_install', 'roles', 0, []); } catch (Throwable $ignored) {}
      $msg = 'RBAC installed successfully.';
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $msg = 'RBAC install failed: ' . $e->getMessage();
    }
  }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="m-0">Install RBAC</h4>
  <a class="btn btn-outline-secondary" href="/admin/roles.php">Back</a>
</div>

<?php if ($msg): ?>
  <div class="alert alert-info"><?= h($msg) ?></div>
<?php endif; ?>

<div class="card p-3 shadow-sm" style="max-width: 760px;">
  <p class="mb-2">This will create RBAC tables (<span class="mono">roles, permissions, user_roles, role_permissions</span>), seed default permissions, and migrate existing users based on their current <span class="mono">admin_users.role</span>.</p>
  <ul class="mb-3">
    <li><b>admin</b> → all permissions</li>
    <li>others → <span class="mono">codes.view</span> only (you can change later)</li>
  </ul>

  <form method="post" class="m-0">
    <?= csrf_input() ?>
    <?php $enabled = rbac_enabled($pdo); ?>
    <button class="btn <?= $enabled?'btn-success':'btn-primary' ?>" type="submit"><?= $enabled?'Sync Permissions':'Install RBAC' ?></button>
    <?php if ($enabled): ?>
      <div class="small text-muted mt-2">RBAC is already installed. Click "Sync Permissions" to add any newly introduced permissions to the database and auto-grant them to the <span class="mono">admin</span> role.</div>
    <?php endif; ?>
  </form>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>