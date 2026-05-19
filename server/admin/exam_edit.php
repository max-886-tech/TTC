<?php
// TrueCerts is dumps-only. Use the TTC dumps editor.
$id = (int)($_GET['id'] ?? 0);
header('Location: /admin/exam_edit_enbl.php' . ($id > 0 ? ('?id=' . $id) : ''));
exit;
