<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\AttemptRepository;

final class AttemptService {
  public function __construct(private AttemptRepository $repo) {}

  /** @return array{rows:array<int,array<string,mixed>>,totalRows:int,totalPages:int,page:int,perPage:int,offset:int,filters:array<string,mixed>,meta:array<string,mixed>} */
  public function paginateForAdmin(array $filters): array {
    return $this->repo->paginateForAdmin($filters);
  }
}
