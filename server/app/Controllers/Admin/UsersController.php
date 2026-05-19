<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Admin\AdminPage;
use App\Repositories\UserRepository;
use App\Services\UserService;

final class UsersController extends BaseAdminController {
  public static function handle(): void {
    (new self())->run();
  }

  private function run(): void {
    $this->boot('users.manage');
    $me = require_login();
    $service = new UserService(new UserRepository($this->pdo));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      csrf_verify();
      $id = (int)$this->post('id', 0);
      $action = (string)$this->post('action', '');
      if ($id > 0 && $id !== (int)($me['id'] ?? 0)) {
        if ($action === 'disable') {
          $service->setActiveStatus($id, false);
          audit_log_event('user_disable', 'admin_users', $id, []);
          $this->flash('info', 'User disabled.');
        } elseif ($action === 'enable') {
          $service->setActiveStatus($id, true);
          audit_log_event('user_enable', 'admin_users', $id, []);
          $this->flash('info', 'User enabled.');
        }
      } else {
        $this->flash('info', 'You cannot modify your own active status here.');
      }
      $this->redirect('/admin/users.php');
    }

    $result = $service->listForAdmin();
    view('admin/users', [
      'rows' => $result['rows'],
      'total' => $result['total'],
      'activeUsers' => $result['active'],
      'inactiveUsers' => $result['inactive'],
      'me' => $me,
      'message' => AdminPage::pullFlash('info'),
    ]);

    AdminPage::finish();
  }
}
