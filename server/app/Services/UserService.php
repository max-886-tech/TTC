<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserRepository;

final class UserService {
  public function __construct(private UserRepository $repo) {}

  public function findById(int $id): ?array {
    return $this->repo->findById($id);
  }

  /** @return array<int,array<string,mixed>> */
  public function search(string $q, int $limit = 25): array {
    return $this->repo->search($q, $limit);
  }

  /** @return array<string,mixed> */
  public function listForAdmin(): array {
    $rows = $this->repo->listForAdmin();
    $activeUsers = 0;
    $inactiveUsers = 0;
    foreach ($rows as $tmpUser) {
      if ((int)($tmpUser['is_active'] ?? 0) === 1) $activeUsers++; else $inactiveUsers++;
    }
    return [
      'rows' => $rows,
      'total' => count($rows),
      'active' => $activeUsers,
      'inactive' => $inactiveUsers,
    ];
  }

  public function setActiveStatus(int $id, bool $active): void {
    $this->repo->setActiveStatus($id, $active);
  }
}
