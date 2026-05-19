<?php
declare(strict_types=1);

namespace App\Services;

final class AccessCodeService {

  /**
   * Normalize admin input for limits.
   * - max_users: minimum 1
   * - max_uses:  0 = unlimited
   */
  public function normalizeLimits(array $in): array {
    $maxUsers = isset($in['max_users']) ? (int)$in['max_users'] : 1;
    if ($maxUsers < 1) $maxUsers = 1;

    $maxUses = isset($in['max_uses']) ? (int)$in['max_uses'] : 0;
    if ($maxUses < 0) $maxUses = 0;

    return [$maxUsers, $maxUses];
  }
}
