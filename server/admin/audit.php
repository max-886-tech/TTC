<?php
require_once __DIR__ . '/_header.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';

require_permission('audit.view');

$pdo = db();
$action = trim($_GET['action'] ?? '');
$target = trim($_GET['target'] ?? '');

$where = [];
$params = [];

if ($action !== '') { $where[] = "action = ?"; $params[] = $action; }
if ($target !== '') { $where[] = "target_type = ?"; $params[] = $target; }

$sqlWhere = $where ? ("WHERE " . implode(" AND ", $where)) : "";

$stmt = $pdo->prepare("
  SELECT a.*, u.username
  FROM audit_log a
  LEFT JOIN admin_users u ON u.id = a.actor_user_id
  $sqlWhere
  ORDER BY a.id DESC
  LIMIT 200
");
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>
<div class="admin-page-header">
  <div>
    <h1 class="admin-page-title">Audit Log</h1>
    <div class="admin-page-subtitle">Latest 200 tracked admin actions across codes, users and configuration.</div>
  </div>
</div>
<div class="admin-panel mb-4"><div class="admin-panel-body">
<form class="row g-2" method="get">
  <div class="col-md-3"><input class="form-control" name="action" placeholder="action (code_create...)" value="<?= h($action) ?>"></div>
  <div class="col-md-3"><input class="form-control" name="target" placeholder="target_type (access_codes...)" value="<?= h($target) ?>"></div>
  <div class="col-md-2"><button class="btn btn-outline-secondary w-100" type="submit">Filter</button></div>
</form>
</div></div>
<div class="admin-panel"><div class="table-responsive table-wrap">
<table class="table table-hover align-middle">
<thead><tr><th>ID</th><th>Time</th><th>User</th><th>Action</th><th>Target</th><th>IP</th><th>Meta</th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
<td class="mono"><?= (int)$r['id'] ?></td>
<td class="mono"><?= h($r['created_at']) ?></td>
<td><?= h($r['username'] ?? 'system') ?></td>
<td class="mono"><?= h($r['action']) ?></td>
<td class="mono"><?= h($r['target_type']) ?>#<?= h($r['target_id'] ?? '') ?></td>
<td class="mono"><?= h($r['ip'] ?? '') ?></td>
<td class="mono small" style="max-width:420px; white-space:pre-wrap; word-break:break-word;"><?= h($r['meta_json'] ?? '') ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No audit records</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require_once __DIR__ . '/_footer.php'; ?>
