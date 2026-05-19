<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Validators\AccessCodeValidator;
use App\Repositories\AccessCodeRepository;
use Throwable;

final class CodeEditController {
  public static function handle(): void {
    require_permission('code.edit');

    $pdo = db();
    $codesRepo = new AccessCodeRepository($pdo);

    $id = Request::getInt('id', 0);
    if ($id <= 0) { echo "Missing id"; exit; }

    $row = $codesRepo->findById($id);
    if (!$row) { echo "Not found"; exit; }

    $msg = '';
    $errors = [];

    if (Request::isPost()) {
      $validator = new AccessCodeValidator();
      csrf_verify();
      $me = require_login();

      $exam_name = Request::postString('exam_name');
      $exam_id   = Request::postString('exam_id');
      $resource_type = 'enbl';
      $quiz_exam_id  = Request::postString('quiz_exam_id');
      if ($resource_type === 'quiz' && $quiz_exam_id === '' && $exam_id !== '') {
        $quiz_exam_id = $exam_id;
      }
      $mode_access = [
        'practice' => ['enabled' => isset($_POST['mode_access']['practice']) ? 1 : 0],
        'exam' => ['enabled' => isset($_POST['mode_access']['exam']) ? 1 : 0],
        'demo' => ['enabled' => isset($_POST['mode_access']['demo']) ? 1 : 0],
      ];
      $user_id = Request::postInt('user_id', 0);
      $user_name   = Request::postString('user_name');
      $user_email  = Request::postString('user_email');
      $user_phone  = Request::postString('user_phone');
      $user_address = Request::postString('user_address');

      if ($user_id > 0) {
        $ust = $pdo->prepare("SELECT id, username, full_name, email, phone, address FROM admin_users WHERE id=? LIMIT 1");
        $ust->execute([$user_id]);
        $ur = $ust->fetch();
        if (!$ur) {
          $errors[] = 'Selected user not found.';
        } else {
          $user_name = trim((string)($ur['full_name'] ?? '')) ?: trim((string)($ur['username'] ?? ''));
          $user_email = trim((string)($ur['email'] ?? ''));
          $user_phone = trim((string)($ur['phone'] ?? ''));
          $user_address = trim((string)($ur['address'] ?? ''));
        }
      }
      $max_users = Request::postInt('max_users', 1);
      $max_uses  = Request::postInt('max_uses', 0);
      $expires_local = Request::postString('expires_local');
      $note = Request::postString('note');
      $is_active = Request::postBool('is_active') ? 1 : 0;

      if ($resource_type === 'quiz') {
        if ($quiz_exam_id === '') {
          $errors[] = 'Please select a dumps file.';
        } else {
          $ex = quiz_get_exam($pdo, $quiz_exam_id);
          if (!$ex || (($ex['resource_type'] ?? '') !== 'quiz')) {
            $errors[] = 'Selected dumps file not found.';
          } else {
            $exam_id = (string)$ex['exam_id'];
            $exam_name = (string)$ex['exam_name'];
          }
        }
      }

      $errors = array_merge($errors, $validator->validateEdit([
        'exam_name' => $exam_name,
        'exam_id' => $exam_id,
        'user_email' => $user_email,
        'max_users' => $max_users,
        'max_uses' => $max_uses,
      ]));

      if ($errors) {
        if ($resource_type === 'quiz' && $quiz_exam_id === '' && $exam_id !== '') {
          $quiz_exam_id = $exam_id;
        }
        $row = array_merge($row, [
          'exam_name' => $exam_name,
          'exam_id' => $exam_id,
          'resource_type' => $resource_type,
          'quiz_exam_id' => $quiz_exam_id,
          'user_id' => $user_id,
          'user_name' => $user_name,
          'user_email' => $user_email,
          'user_phone' => $user_phone,
          'user_address' => $user_address,
          'note' => $note,
          'is_active' => $is_active,
          'mode_access_json' => $resource_type === 'quiz' ? json_encode($mode_access, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
      } else {
        if ($max_users <= 0) $max_users = 1;
        if ($max_uses < 0) $max_uses = 0;
        $expires_at = parse_datetime_local_to_utc($expires_local);

        $r2_key = '';
        $r2_url = '';
        if ($resource_type === 'enbl') {
          try {
            $ex = $pdo->prepare("SELECT exam_name, r2_key, r2_url FROM exams WHERE exam_id=? LIMIT 1");
            $ex->execute([$exam_id]);
            $er = $ex->fetch();
            if ($er) {
              if (trim((string)($er['exam_name'] ?? '')) !== '') {
                $exam_name = (string)$er['exam_name'];
              }
              $r2_key = (string)($er['r2_key'] ?? '');
              $r2_url = (string)($er['r2_url'] ?? '');
            }
          } catch (Throwable $e) {
          }
        }

        $data = [
          'exam_name'    => $exam_name,
          'exam_id'      => $exam_id,
          'resource_type'=> $resource_type ?: 'enbl',
          'quiz_exam_id' => $resource_type === 'quiz' ? ($quiz_exam_id ?: $exam_id) : null,
          'user_id'      => $user_id > 0 ? $user_id : null,
          'user_name'    => $user_name ?: null,
          'user_email'   => $user_email ?: null,
          'user_phone'   => $user_phone ?: null,
          'user_address' => $user_address ?: null,
          'r2_key'       => $resource_type === 'enbl' ? ($r2_key ?: null) : null,
          'r2_url'       => $resource_type === 'enbl' ? ($r2_url ?: null) : null,
          'expires_at'   => $expires_at,
          'is_active'    => $is_active,
          'note'         => $note ?: null,
          'updated_by'   => (int)$me['id'],
          'mode_access_json' => $resource_type === 'quiz' ? json_encode($mode_access, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ];

        if (db_has_column($pdo, 'access_codes', 'max_users')) {
          $data['max_users'] = $max_users;
        } else {
          $data['max_uses'] = $max_users;
        }
        if (db_has_column($pdo, 'access_codes', 'max_uses')) {
          $data['max_uses'] = $max_uses;
        }

        $codesRepo->update($id, $data);

        audit_log_event('code_edit', 'access_codes', $id, [
          'exam_id' => $exam_id,
          'exam_name' => $exam_name,
          'resource_type' => $resource_type ?: 'enbl',
          'quiz_exam_id' => $resource_type === 'quiz' ? ($quiz_exam_id ?: $exam_id) : null,
          'user_id' => $user_id > 0 ? $user_id : null,
          'user_name' => $user_name ?: null,
          'max_users' => $max_users,
          'max_uses'  => $max_uses,
          'expires_at_utc' => $expires_at,
          'is_active' => $is_active,
          'note' => $note ?: null,
          'user_email' => $user_email ?: null,
          'user_phone' => $user_phone ?: null,
          'mode_access' => $resource_type === 'quiz' ? $mode_access : null
        ]);

        $msg = "Updated.";
        $row = $codesRepo->findById($id) ?: $row;
      }
    }

    $expires_local_value = '';
    if (!empty($row['expires_at'])) {
      $utc = new \DateTime((string)$row['expires_at'], new \DateTimeZone('UTC'));
      $utc->setTimezone(new \DateTimeZone(date_default_timezone_get()));
      $expires_local_value = $utc->format('Y-m-d\\TH:i');
    }

    view('admin/code_edit', [
      'row' => $row,
      'msg' => $msg,
      'errors' => $errors,
      'expires_local_value' => $expires_local_value,
    ]);
  }
}
