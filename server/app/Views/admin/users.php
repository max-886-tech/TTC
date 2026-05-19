<div class="admin-page-header">
  <div>
    <h1 class="admin-page-title">Users</h1>
    <div class="admin-page-subtitle">Manage users, profile details, roles and active access.</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="/admin/roles.php"><i class="bi bi-shield-lock me-1"></i> User Roles</a>
    <a class="btn btn-primary" href="/admin/user_new.php"><i class="bi bi-plus-lg me-1"></i> Add User</a>
  </div>
</div>
<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="admin-kpi"><div class="admin-kpi-label">Total Users</div><div class="admin-kpi-value"><?= (int)$total ?></div></div></div>
  <div class="col-md-4"><div class="admin-kpi"><div class="admin-kpi-label">Active Users</div><div class="admin-kpi-value"><?= (int)$activeUsers ?></div></div></div>
  <div class="col-md-4"><div class="admin-kpi"><div class="admin-kpi-label">Inactive Users</div><div class="admin-kpi-value"><?= (int)$inactiveUsers ?></div></div></div>
</div>
<?php if (!empty($message)): ?><div class="alert alert-info"><?= h($message) ?></div><?php endif; ?>

<div class="admin-panel overflow-hidden">
  <div class="admin-panel-header">
    <div>
      <div class="admin-section-title">User Directory</div>
      <div class="admin-section-subtitle">Search-friendly customer and admin profile data in one place.</div>
    </div>
    <span class="wp-pill wp-pill-blue"><i class="bi bi-people"></i> <?= (int)$total ?> accounts</span>
  </div>
  <div class="table-responsive table-wrap" style="max-height: calc(100vh - 290px);">
    <table class="table table-hover align-middle admin-table-compact table-sticky">
      <thead><tr><th>ID</th><th>User</th><th>Contact</th><th>Role</th><th>Status</th><th>Last Login</th><th>Created</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $u): $isSelf = (int)$u['id'] === (int)$me['id']; $displayName = trim((string)($u['full_name'] ?? '')) ?: (string)($u['username'] ?? ''); ?>
        <tr>
          <td><span class="wp-pill wp-pill-gray"><i class="bi bi-hash"></i> <?= (int)$u['id'] ?></span></td>
          <td><div class="admin-user-cell"><span class="admin-icon-badge"><i class="bi bi-person"></i></span><div class="admin-meta-stack"><span class="title"><?= h($displayName) ?></span><span class="sub">@<?= h((string)($u['username'] ?? '')) ?><?= $isSelf ? ' · Current signed-in user' : '' ?></span></div></div></td>
          <td><div class="small"><?= h((string)($u['email'] ?? '—')) ?></div><div class="small text-muted"><?= h((string)($u['phone'] ?? '—')) ?></div></td>
          <td><span class="wp-pill wp-pill-blue"><i class="bi bi-person-badge"></i> <?= h((string)($u['display_role'] ?? $u['role'] ?? '')) ?></span></td>
          <td><?php if ((int)($u['is_active'] ?? 0)): ?><span class="wp-pill wp-pill-green"><i class="bi bi-check-circle"></i> Active</span><?php else: ?><span class="wp-pill wp-pill-gray"><i class="bi bi-pause-circle"></i> Disabled</span><?php endif; ?></td>
          <td class="mono small"><?= h((string)($u['last_login_at'] ?? '—') ?: '—') ?></td>
          <td class="mono small"><?= h((string)($u['created_at'] ?? '—') ?: '—') ?></td>
          <td class="text-end"><div class="d-inline-flex flex-wrap gap-2 justify-content-end"><a class="btn btn-sm btn-outline-primary" href="/admin/user_edit.php?id=<?= (int)$u['id'] ?>"><i class="bi bi-pencil-square me-1"></i> Edit</a><?php if (!$isSelf): ?><form method="post" class="d-inline"><?= csrf_input() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><?php if ((int)($u['is_active'] ?? 0)): ?><input type="hidden" name="action" value="disable"><button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-slash-circle me-1"></i> Disable</button><?php else: ?><input type="hidden" name="action" value="enable"><button class="btn btn-sm btn-outline-success" type="submit"><i class="bi bi-check-circle me-1"></i> Enable</button><?php endif; ?></form><?php else: ?><span class="wp-pill wp-pill-amber"><i class="bi bi-star"></i> You</span><?php endif; ?></div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
