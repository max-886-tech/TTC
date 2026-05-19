<?php
require_once __DIR__ . '/../bootstrap.php';
require_permission('code.generate');

header('Content-Type: application/json; charset=utf-8');
$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
  echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$pdo = db();
$params = ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%'];
$sql = "SELECT id, username, full_name, email, phone, address, role, is_active
        FROM admin_users
        WHERE is_active = 1
          AND (
            username LIKE ?
            OR COALESCE(full_name, '') LIKE ?
            OR COALESCE(email, '') LIKE ?
          )
        ORDER BY
          CASE WHEN role = 'attempt_exam' THEN 0 ELSE 1 END,
          COALESCE(full_name, username), username
        LIMIT 20";
if (!db_has_column($pdo, 'admin_users', 'full_name')) {
  $sql = "SELECT id, username, NULL AS full_name, NULL AS email, NULL AS phone, NULL AS address, role, is_active
          FROM admin_users
          WHERE is_active = 1 AND username LIKE ?
          ORDER BY username
          LIMIT 20";
  $params = ['%' . $q . '%'];
}
$st = $pdo->prepare($sql);
$st->execute($params);
$items = [];
foreach ($st->fetchAll() as $r) {
  $items[] = [
    'id' => (int)$r['id'],
    'username' => (string)($r['username'] ?? ''),
    'full_name' => (string)($r['full_name'] ?? ''),
    'email' => (string)($r['email'] ?? ''),
    'phone' => (string)($r['phone'] ?? ''),
    'address' => (string)($r['address'] ?? ''),
    'role' => (string)($roleMap[(int)($r['id'] ?? 0)] ?? ($r['role'] ?? '')), 
  ];
}
echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
