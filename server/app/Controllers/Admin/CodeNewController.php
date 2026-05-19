<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Validators\AccessCodeValidator;
use App\Repositories\AccessCodeRepository;
use Throwable;

final class CodeNewController {
  public static function handle(): void {
    require_permission('code.generate');

    $pdo = db();
    $codesRepo = new AccessCodeRepository($pdo);

    $msg = '';
    $new_code = null;
    $r2_msg = '';
    $errors = [];

    if (Request::isPost()) {
      $validator = new AccessCodeValidator();
      csrf_verify();
      $me = require_login();

      $exam_name = trim((string)($_POST['exam_name'] ?? ''));
      $exam_id   = trim((string)($_POST['exam_id'] ?? ''));
      $user_id = (int)($_POST['user_id'] ?? 0);
      $user_name = trim((string)($_POST['user_name'] ?? ''));
      $user_email = trim((string)($_POST['user_email'] ?? ''));
      $user_phone = trim((string)($_POST['user_phone'] ?? ''));
      $user_address = trim((string)($_POST['user_address'] ?? ''));

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
      $resource_type = 'enbl';
      $quiz_exam_id  = trim((string)($_POST['quiz_exam_id'] ?? ''));
      $mode_access = [
        'practice' => ['enabled' => isset($_POST['mode_access']['practice']) ? 1 : 0],
        'exam' => ['enabled' => isset($_POST['mode_access']['exam']) ? 1 : 0],
        'demo' => ['enabled' => isset($_POST['mode_access']['demo']) ? 1 : 0],
      ];

      $max_users = (int)($_POST['max_users'] ?? 1);
      $max_uses = (int)($_POST['max_uses'] ?? 0);
      $days = (int)($_POST['days'] ?? ($_POST['expires_days'] ?? 0));
      $expires_local = (string)($_POST['expires_local'] ?? '');
      $note = trim((string)($_POST['note'] ?? ''));
      $is_active = isset($_POST['is_active']) ? 1 : 0;

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

      if (!$errors) {
        $errors = $validator->validateCreate([
          'exam_name' => $exam_name,
          'exam_id' => $exam_id,
          'user_email' => $user_email,
          'max_users' => $max_users,
          'max_uses' => $max_uses,
        ]);
      }

      if ($user_name === '') {
        $errors[] = 'User Name is required.';
      }

      if (!$errors) {
        if ($max_users <= 0) $max_users = 1;
        if ($max_uses < 0) $max_uses = 0;

        $code_plain = gen_code();
        $expires_at = parse_datetime_local_to_utc($expires_local);
        if (!$expires_at) {
          $expires_at = parse_days_to_expires_at($days);
        }

        $r2_key = '';
        $r2_url = '';
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

        if ($resource_type === 'enbl' && $r2_key === '' && $r2_url === '') {
          $r2_msg = 'Note: This Exam has no R2 file linked. Code will still be created, but downloads may not work until Exam is linked to an R2 file.';
        } elseif ($resource_type === 'quiz') {
          $r2_key = '';
          $r2_url = '';
          $r2_msg = 'Quiz code (no file)';
        }

        $data = [
          'code_hash'     => code_hash($code_plain),
          'code_plain'    => $code_plain,
          'exam_name'     => $exam_name,
          'exam_id'       => $exam_id,
          'resource_type' => $resource_type ?: 'enbl',
          'quiz_exam_id'  => $resource_type === 'quiz' ? $quiz_exam_id : null,
          'user_id'       => $user_id > 0 ? $user_id : null,
          'user_name'     => $user_name ?: null,
          'user_email'    => $user_email ?: null,
          'user_phone'    => $user_phone ?: null,
          'user_address'  => $user_address ?: null,
          'r2_key'        => $resource_type === 'enbl' ? ($r2_key ?: null) : null,
          'r2_url'        => $resource_type === 'enbl' ? ($r2_url ?: null) : null,
          'expires_at'    => $expires_at,
          'is_active'     => $is_active,
          'note'          => $note ?: null,
          'created_by'    => (int)$me['id'],
          'updated_by'    => (int)$me['id'],
          'uses_count'    => 0,
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

        $new_id = $codesRepo->create($data);

        audit_log_event('code_generate', 'access_codes', $new_id, [
          'code_plain' => $code_plain,
          'exam_id' => $exam_id,
          'exam_name' => $exam_name,
          'max_users' => $max_users,
          'max_uses'  => $max_uses,
          'expires_at_utc' => $expires_at,
          'is_active' => $is_active,
          'note' => $note ?: null,
          'resource_type' => $resource_type ?: 'enbl',
          'mode_access' => $resource_type === 'quiz' ? $mode_access : null
        ]);

        $new_code = $code_plain;
        $msg = 'Created.';
      }
    }

    view('admin/code_new', [
      'msg' => $msg,
      'new_code' => $new_code,
      'r2_msg' => $r2_msg,
      'errors' => $errors,
    ]);
  }
}
