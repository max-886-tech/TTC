<?php
declare(strict_types=1);

namespace App\Validators;

final class AccessCodeValidator {
  public function validateCreate(array $in): array {
    return $this->validateCommon($in, true);
  }

  public function validateEdit(array $in): array {
    return $this->validateCommon($in, false);
  }

  private function validateCommon(array $in, bool $isCreate): array {
    $errors = [];

    $exam_id = trim((string)($in['exam_id'] ?? ''));
    $exam_name = trim((string)($in['exam_name'] ?? ''));

    if ($exam_id === '') $errors[] = "Exam Code is required.";
    if ($exam_name === '') $errors[] = "Exam Name is required.";

    $max_users = (int)($in['max_users'] ?? 1);
    $max_uses  = (int)($in['max_uses'] ?? 0);

    if ($max_users < 1) $errors[] = "Max Users must be 1 or more.";
    if ($max_uses < 0) $errors[] = "Max Uses cannot be negative.";

    $email = trim((string)($in['user_email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $errors[] = "User Email is not valid.";
    }

    // expires_local is optional; parsing happens in controller (so we can use existing helper).
    return $errors;
  }
}
