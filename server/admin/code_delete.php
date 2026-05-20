<?php
require_once __DIR__ . '/_header.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/audit.php';

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { echo "Missing id"; exit; }

$stmt = $pdo->prepare("SELECT * FROM access_codes WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) { echo "Not found"; exit; }

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $pdo->beginTransaction();
  try {
    $del = $pdo->prepare("DELETE FROM access_codes WHERE id=? LIMIT 1");
    $del->execute([$id]);

    audit_log_event('code_delete', 'access_codes', $id, [
      'exam_id' => $row['exam_id'] ?? null,
      'exam_name' => $row['exam_name'] ?? null,
      'code_plain' => $row['code_plain'] ?? null,
      'user_email' => $row['user_email'] ?? null,
      'user_phone' => $row['user_phone'] ?? null,
      'note' => $row['note'] ?? null,
    ]);

    $pdo->commit();
    header('Location: /admin/?deleted=1');
    exit;
  } catch (Exception $e) {
    $pdo->rollBack();
    $msg = 'Delete failed: ' . $e->getMessage();
  }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="m-0">Delete Code #<?= (int)$row['id'] ?></h4>
  <a class="btn btn-outline-secondary" href="/admin/code_edit.php?id=<?= (int)$row['id'] ?>">Back</a>
</div>

<?php if ($msg): ?>
  <div class="alert alert-danger"><?= h($msg) ?></div>
<?php endif; ?>

<div class="card p-3 shadow-sm" style="max-width: 900px;">
  <div class="alert alert-warning">
    <div class="fw-semibold mb-1">This will permanently delete the access code.</div>
    <div class="small">This action cannot be undone.</div>
  </div>

  <div class="row g-2 mb-3">
    <div class="col-md-6">
      <div class="text-muted small">Dumps</div>
      <div><strong><?= h($row['exam_name'] ?? '') ?></strong></div>
      <div class="mono text-muted small"><?= h($row['exam_id'] ?? '') ?></div>
    </div>
    <div class="col-md-6">
      <div class="text-muted small">Code</div>
      <div class="mono fs-5"><?= !empty($row['code_plain']) ? h($row['code_plain']) : '[hidden]' ?></div>
    </div>
  </div>

  <form method="post" class="d-flex gap-2">
    <?= csrf_input() ?>
    <button class="btn btn-danger" type="submit">
      <i class="bi bi-trash"></i> Delete
    </button>
    <a class="btn btn-outline-secondary" href="/admin/code_edit.php?id=<?= (int)$row['id'] ?>">Cancel</a>
  </form>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
