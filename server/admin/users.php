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
  $id = (int)($_POST['id'] ?? 0);
  $action = $_POST['action'] ?? '';

  if ($id > 0 && $id !== (int)$me['id']) { // prevent disabling yourself
    if ($action === 'disable') {
      $pdo->prepare("UPDATE admin_users SET is_active=0 WHERE id=?")->execute([$id]);
      audit_log_event('user_disable', 'admin_users', $id, []);
      $msg = "User disabled.";
    } elseif ($action === 'enable') {
      $pdo->prepare("UPDATE admin_users SET is_active=1 WHERE id=?")->execute([$id]);
      audit_log_event('user_enable', 'admin_users', $id, []);
      $msg = "User enabled.";
    }
  } else {
    $msg = "You cannot modify your own active status here.";
  }
}

$rows = $pdo->query("SELECT id, username, role, is_active, last_login_at, created_at FROM admin_users ORDER BY id DESC")->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="m-0">Users</h4>
  <a class="btn btn-primary" href="/admin/user_new.php">+ Add User</a>
</div>

<?php if ($msg): ?>
  <div class="alert alert-info"><?= h($msg) ?></div>
<?php endif; ?>

<div class="table-responsive">
  <table class="table table-sm table-striped align-middle">
    <thead>
      <tr>
        <th>ID</th>
        <th>Username</th>
        <th>Role</th>
        <th>Active</th>
        <th>Last Login</th>
        <th>Created</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $u): ?>
      <tr>
        <td class="mono"><?= (int)$u['id'] ?></td>
        <td><?= h($u['username']) ?></td>
        <td><?= h($u['role']) ?></td>
        <td><?= (int)$u['is_active'] ? '<span class="badge text-bg-success">Yes</span>' : '<span class="badge text-bg-secondary">No</span>' ?></td>
        <td class="mono"><?= h($u['last_login_at'] ?? '') ?></td>
        <td class="mono"><?= h($u['created_at'] ?? '') ?></td>
        <td class="text-end">
          <a class="btn btn-sm btn-outline-primary" href="/admin/user_edit.php?id=<?= (int)$u['id'] ?>">Edit</a>

          <?php if ((int)$u['id'] !== (int)$me['id']): ?>
            <form method="post" class="d-inline">
              <?= csrf_input() ?>
              <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <?php if ((int)$u['is_active']): ?>
                <input type="hidden" name="action" value="disable">
                <button class="btn btn-sm btn-outline-danger" type="submit">Disable</button>
              <?php else: ?>
                <input type="hidden" name="action" value="enable">
                <button class="btn btn-sm btn-outline-success" type="submit">Enable</button>
              <?php endif; ?>
            </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
