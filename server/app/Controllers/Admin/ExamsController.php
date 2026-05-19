<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Admin\AdminPage;
use App\Repositories\ExamRepository;
use App\Services\ExamService;

final class ExamsController extends BaseAdminController {
  public static function handle(): void {
    (new self())->run();
  }

  private function run(): void {
    $this->boot('exams.manage');

    $service = new ExamService(new ExamRepository($this->pdo));

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($this->post('action', '')) === 'delete_exam')) {
      csrf_verify();
      $deleteId = (int)$this->post('id', 0);
      if ($deleteId > 0) {
        $result = $service->deleteExam($deleteId);
        if (($result['ok'] ?? false) === true) {
          $this->flash('success', 'Exam deleted successfully.');
        } elseif (($result['reason'] ?? '') === 'not_found') {
          $this->flash('warning', 'Exam not found.');
        } else {
          $this->flash('danger', 'Unable to delete exam right now.');
        }
      } else {
        $this->flash('warning', 'Invalid exam selected.');
      }
      $this->redirect('/admin/exams.php');
    }

    $result = $service->listForAdmin();
    view('admin/exams', [
      'rows' => $result['rows'],
      'questionCounts' => $result['questionCounts'],
      'total' => $result['total'],
      'quizCount' => $result['quizCount'],
      'enblCount' => $result['enblCount'],
      'successMessage' => AdminPage::pullFlash('success'),
      'warningMessage' => AdminPage::pullFlash('warning'),
      'dangerMessage' => AdminPage::pullFlash('danger'),
    ]);

    AdminPage::finish();
  }
}
