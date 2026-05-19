<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Admin\AdminPage;

final class UploadsController extends BaseAdminController {
  public static function handle(): void {
    (new self())->run();
  }

  private function run(): void {
    require_once APP_ROOT . '/admin/_header.php';
    if (!(can('quiz.manage') || can('exams.manage'))) {
      http_response_code(403);
      echo 'Forbidden';
      exit;
    }
    $this->pdo = db();
    quiz_safe_ensure_schema($this->pdo);
    view('admin/uploads', [
      'csrfToken' => csrf_token(),
    ]);
    AdminPage::finish();
  }
}
