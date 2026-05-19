<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\MediaRepository;

final class MediaService {
  public function __construct(private MediaRepository $repo) {}

  public function findById(int $id): ?array {
    return $this->repo->findById($id);
  }

  /** @return array<int,array<string,mixed>> */
  public function recent(int $limit = 50): array {
    return $this->repo->recent($limit);
  }
}
