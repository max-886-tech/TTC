<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Admin\AdminPage;
use PDO;

abstract class BaseAdminController {
  protected PDO $pdo;

  final public function __construct() {
    $this->pdo = db();
  }

  protected function boot(string $permission): PDO {
    $this->pdo = AdminPage::boot($permission);
    return $this->pdo;
  }

  protected function redirect(string $url): never {
    AdminPage::redirect($url);
  }

  protected function flash(string $key, string $message): void {
    AdminPage::flash($key, $message);
  }

  protected function query(string $key, mixed $default = null): mixed {
    return $_GET[$key] ?? $default;
  }

  protected function post(string $key, mixed $default = null): mixed {
    return $_POST[$key] ?? $default;
  }
}
