<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class UserRepository {
  public function __construct(private PDO $pdo) {}

  public function findById(int $id): ?array {
    $st = $this->pdo->prepare('SELECT * FROM admin_users WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }

  /** @return array<int,array<string,mixed>> */
  public function search(string $q, int $limit = 25): array {
    $q = trim($q);
    if ($q === '') return [];
    $like = '%' . $q . '%';
    $limit = max(1, min(100, $limit));
    $sql = "SELECT id, username, full_name, email FROM admin_users
            WHERE username LIKE ? OR full_name LIKE ? OR email LIKE ?
            ORDER BY id DESC LIMIT {$limit}";
    $st = $this->pdo->prepare($sql);
    $st->execute([$like, $like, $like]);
    return $st->fetchAll() ?: [];
  }

  /** @return array<int,array<string,mixed>> */
  public function listForAdmin(): array {
    $hasProfile = db_has_column($this->pdo, 'admin_users', 'full_name');
    $sql = "SELECT id, username, role, is_active, last_login_at, created_at" . ($hasProfile ? ", full_name, email, phone, address" : '') . " FROM admin_users ORDER BY id DESC";
    $rows = $this->pdo->query($sql)->fetchAll() ?: [];

    if (rbac_enabled($this->pdo) && db_has_table($this->pdo, 'roles') && db_has_table($this->pdo, 'user_roles')) {
      $roleMap = [];
      foreach ($this->pdo->query("SELECT ur.user_id, r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id ORDER BY r.name")->fetchAll() ?: [] as $rr) {
        $uid = (int)($rr['user_id'] ?? 0);
        if ($uid > 0 && !isset($roleMap[$uid])) $roleMap[$uid] = (string)($rr['name'] ?? '');
      }
      foreach ($rows as &$u) {
        $uid = (int)($u['id'] ?? 0);
        if (!empty($roleMap[$uid])) $u['display_role'] = $roleMap[$uid];
      }
      unset($u);
    }

    return $rows;
  }

  public function setActiveStatus(int $id, bool $active): void {
    $this->pdo->prepare('UPDATE admin_users SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
  }
}
