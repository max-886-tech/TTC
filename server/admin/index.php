<?php
require_once __DIR__ . '/_header.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';

require_permission('codes.view');

$pdo = db();

$q_exam = trim($_GET['exam'] ?? '');
$q_search = trim($_GET['search'] ?? '');
$q_active = $_GET['active'] ?? '';
$q_expired = $_GET['expired'] ?? '';

$where = [];
$params = [];

if ($q_exam !== '') {
  $where[] = "(exam_id LIKE ? OR exam_name LIKE ?)";
  $params[] = '%' . $q_exam . '%';
  $params[] = '%' . $q_exam . '%';
}
if ($q_active === '1' || $q_active === '0') {
  $where[] = "is_active = ?";
  $params[] = (int)$q_active;
}
if ($q_expired === '1') {
  $where[] = "expires_at IS NOT NULL AND expires_at < ?";
  $params[] = utc_now_sql();
}
if ($q_search !== '') {
  $where[] = "(code_plain LIKE ? OR note LIKE ? OR user_email LIKE ? OR user_phone LIKE ?)";
  $params[] = '%' . $q_search . '%';
  $params[] = '%' . $q_search . '%';
  $params[] = '%' . $q_search . '%';
  $params[] = '%' . $q_search . '%';
}

$sqlWhere = $where ? ("WHERE " . implode(" AND ", $where)) : "";

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$off = ($page - 1) * $perPage;

$count = $pdo->prepare("SELECT COUNT(*) AS c FROM access_codes $sqlWhere");
$count->execute($params);
$total = (int)($count->fetch()['c'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM access_codes $sqlWhere ORDER BY id DESC LIMIT $perPage OFFSET $off");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pages = max(1, (int)ceil($total / $perPage));
$activeCount = 0;
$expiredCount = 0;
foreach ($rows as $tmpRow) {
  if ((int)($tmpRow['is_active'] ?? 0) === 1) $activeCount++;
  if (!empty($tmpRow['expires_at']) && $tmpRow['expires_at'] < utc_now_sql()) $expiredCount++;
}
?>
<div class="admin-page-header">
  <div>
    <h1 class="admin-page-title">Access Codes</h1>
    <div class="admin-page-subtitle">Manage generated codes, usage limits, expiry and assigned users.</div>
  </div>
  <?php if (can('code.generate')): ?>
    <a class="btn btn-primary" href="/admin/code_new.php"><i class="bi bi-plus-circle me-1"></i> Generate Code</a>
  <?php endif; ?>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="admin-kpi"><div class="admin-kpi-label">Visible Codes</div><div class="admin-kpi-value"><?= (int)$total ?></div></div></div>
  <div class="col-md-4"><div class="admin-kpi"><div class="admin-kpi-label">Active on this page</div><div class="admin-kpi-value"><?= (int)$activeCount ?></div></div></div>
  <div class="col-md-4"><div class="admin-kpi"><div class="admin-kpi-label">Expired on this page</div><div class="admin-kpi-value"><?= (int)$expiredCount ?></div></div></div>
</div>

<div class="admin-panel mb-4">
  <div class="admin-panel-body">
    <form class="row g-2" method="get">
      <div class="col-md-3">
        <input class="form-control" name="exam" placeholder="Exam Name / Exam Code" value="<?= h($q_exam) ?>">
      </div>
      <div class="col-md-2">
        <select class="form-select" name="active">
          <option value="">Active?</option>
          <option value="1" <?= $q_active==='1'?'selected':'' ?>>Active</option>
          <option value="0" <?= $q_active==='0'?'selected':'' ?>>Inactive</option>
        </select>
      </div>
      <div class="col-md-2">
        <select class="form-select" name="expired">
          <option value="">Expired?</option>
          <option value="1" <?= $q_expired==='1'?'selected':'' ?>>Expired only</option>
        </select>
      </div>
      <div class="col-md-4">
        <input class="form-control" name="search" placeholder="Search code / note / email / phone" value="<?= h($q_search) ?>">
      </div>
      <div class="col-md-1">
        <button class="btn btn-outline-secondary w-100" type="submit">Go</button>
      </div>
    </form>
  </div>
</div>

<div class="admin-panel">
  <div class="table-responsive table-wrap">
    <table class="table table-hover align-middle">
      <thead>
        <tr>
          <th>ID</th>
          <th>Exam</th>
          <th>Code</th>
          <th>Email / Phone</th>
          <th>Active</th>
          <th>Users / Uses</th>
          <th>Device</th>
          <th>Expires (UTC)</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $nowUtc = utc_now_sql();
            $expired = (!empty($r['expires_at']) && $r['expires_at'] < $nowUtc);
          ?>
          <tr>
            <td class="mono"><?= (int)$r['id'] ?></td>
            <td>
              <div><strong><?= h($r['exam_name'] ?? '') ?></strong></div>
              <div class="text-muted small mono"><?= h($r['exam_id'] ?? '') ?></div>
            </td>
            <td class="mono">
              <?= !empty($r['code_plain']) ? h($r['code_plain']) : '<span class="text-muted">[hidden]</span>' ?>
              <?php if (!empty($r['note'])): ?>
                <div class="text-muted small"><?= h($r['note']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <div><?= h($r['user_email'] ?? '') ?></div>
              <div class="text-muted small"><?= h($r['user_phone'] ?? '') ?></div>
            </td>
            <td>
              <?php if ((int)$r['is_active'] === 1): ?>
                <span class="badge text-bg-success">Yes</span>
              <?php else: ?>
                <span class="badge text-bg-secondary">No</span>
              <?php endif; ?>
              <?php if ($expired): ?>
                <span class="badge text-bg-danger">Expired</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="small">Users: <?= (int)($r['used_count'] ?? 0) ?> / <?= (int)($r['max_users'] ?? 1) ?></div>
              <div class="small text-muted">Uses: <?= (int)($r['uses_count'] ?? 0) ?> / <?= ((int)($r['max_uses'] ?? 0)===0 ? '∞' : (int)$r['max_uses']) ?></div>
            </td>
            <td class="mono"><?= !empty($r['device_hash']) ? h(substr($r['device_hash'], 0, 10)) . '…' : '<span class="text-muted">-</span>' ?></td>
            <td class="mono"><?= h($r['expires_at'] ?? '') ?></td>
            <td class="text-end">
              <?php if (can('code.edit')): ?>
                <a class="btn btn-sm btn-outline-primary" href="/admin/code_edit.php?id=<?= (int)$r['id'] ?>" title="Edit"><i class="bi bi-pencil-square"></i></a>
              <?php endif; ?>
              <?php if (can('code.reset')): ?>
                <a class="btn btn-sm btn-outline-warning" href="/admin/code_reset.php?id=<?= (int)$r['id'] ?>" title="Reset Device"><i class="bi bi-arrow-counterclockwise"></i></a>
              <?php endif; ?>
              <?php if (can('code.delete')): ?>
                <a class="btn btn-sm btn-outline-danger" href="/admin/code_delete.php?id=<?= (int)$r['id'] ?>" title="Delete"><i class="bi bi-trash"></i></a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">No results</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<nav class="mt-3">
  <ul class="pagination">
    <?php for ($p=1; $p<=$pages; $p++): ?>
      <?php $qs = $_GET; $qs['page'] = $p; $url = '/admin/?' . http_build_query($qs); ?>
      <li class="page-item <?= $p===$page?'active':'' ?>">
        <a class="page-link" href="<?= h($url) ?>"><?= $p ?></a>
      </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php require_once __DIR__ . '/_footer.php'; ?>
