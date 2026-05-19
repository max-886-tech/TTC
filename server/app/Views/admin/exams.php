<div class="admin-page-header">
  <div>
    <h1 class="admin-page-title">Dumps</h1>
    <div class="admin-page-subtitle">Manage TrueCerts .ttc dumps files.</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-outline-dark" href="/admin/exam_new_enbl.php"><i class="bi bi-file-earmark-lock2 me-1"></i> New Dumps</a>
    <a class="btn btn-outline-secondary" href="/admin/r2_manager.php"><i class="bi bi-folder2-open me-1"></i> R2 Manager</a>
  </div>
</div>

<?php if (!empty($successMessage)): ?><div class="alert alert-success"><?= h($successMessage) ?></div><?php endif; ?>
<?php if (!empty($warningMessage)): ?><div class="alert alert-warning"><?= h($warningMessage) ?></div><?php endif; ?>
<?php if (!empty($dangerMessage)): ?><div class="alert alert-danger"><?= h($dangerMessage) ?></div><?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-md-6"><div class="admin-kpi"><div class="admin-kpi-label">Total Dumps</div><div class="admin-kpi-value"><?= (int)$total ?></div></div></div>
  <div class="col-md-6"><div class="admin-kpi"><div class="admin-kpi-label">TTC Files</div><div class="admin-kpi-value"><?= (int)$enblCount ?></div></div></div>
</div>

<div class="admin-panel">
  <div class="table-responsive table-wrap">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Dumps ID</th>
          <th>Name</th>
          <th>Type</th>
                    <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $e): ?>
          <?php $type = (string)($e['resource_type'] ?? ''); $qid = (string)($e['exam_id'] ?? ''); $qcount = $questionCounts[$qid] ?? 0; ?>
          <tr>
            <td class="mono"><?= h((string)($e['exam_id'] ?? '')) ?></td>
            <td>
              <div class="fw-semibold"><?= h((string)($e['exam_name'] ?? '')) ?></div>
              <div class="small text-muted"><?= $type === 'quiz' ? 'Dumps configuration' : 'TTC dumps file configuration' ?></div>
            </td>
            <td><span class="badge text-bg-secondary">TTC</span></td>
            <td class="text-end">
              <div class="d-inline-flex gap-2 flex-wrap justify-content-end">
                <a class="btn btn-sm btn-dark" href="/admin/exam_edit_enbl.php?id=<?= (int)$e['id'] ?>"><i class="bi bi-pencil me-1"></i> Edit</a>
                <form method="post" class="d-inline" onsubmit="return confirm('Delete this dumps file? This action cannot be undone.');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_exam">
                  <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i> Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="4" class="text-center text-muted py-5">No dumps files yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
