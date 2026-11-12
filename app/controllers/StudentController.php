<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class StudentController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->library('api');
        $this->call->model('StudentModel');
    }

    private function admin(callable $work): void
    {
        header('Cache-Control: private, no-store');
        load_class('AuthMiddleware', 'middlewares');
        $middleware = new AuthMiddleware([
            'ADMIN'
        ]);
        $middleware->handle(function () use ($work) {
            $work();
        });
    }

    private function input(): array
    {
        $value = json_decode(file_get_contents('php://input'), true);
        return is_array($value) ? $value : [];
    }

    private function fail(string $message, int $status = 400): void
    {
        $this->api->respond([
            'success' => false,
            'message' => $message
        ], $status);
    }

    private function validate(array $v): ?array
    {
        $programs = $this->StudentModel->programs();
        $d = [
            'student_number' => strtoupper(trim((string) ($v['student_number'] ?? ''))),
            'full_name' => trim((string) ($v['full_name'] ?? '')),
            'section' => strtoupper(trim((string) ($v['section'] ?? ''))),
            'course' => strtoupper(trim((string) ($v['course'] ?? ''))),
            'year_level' => trim((string) ($v['year_level'] ?? '')),
        ];
        if (!preg_match('/^MCC-[0-9]{4}-[0-9]{5}$/', $d['student_number']) || $d['full_name'] === '' || mb_strlen($d['full_name']) > 150 || !preg_match('/^[A-Z]+[1-9][0-9]*$/', $d['section']) || mb_strlen($d['section']) > 50 || !in_array($d['course'], array_merge(array_keys($programs), [
            'CCS',
            'CAS',
            'CRIM'
        ]), true) || !in_array($d['year_level'], [
            '1st Year',
            '2nd Year',
            '3rd Year',
            '4th Year'
        ], true)) {
            return null;
        }
        return $d;
    }

    private function createRecord(array $d): array
    {
        $code = strtoupper(bin2hex(random_bytes(6)));
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        $expires = $now->modify('+7 days')->format('Y-m-d H:i:s');
        $token = bin2hex(random_bytes(32));
        $ok = $this->StudentModel->register_student_record([
            ...$d,
            'user_id' => null,
            'account_status' => 'ACTIVE',
            'activation_status' => 'PENDING',
            'qr_token' => $token,
            'qr_token_status' => 'ACTIVE',
            'qr_token_generated_at' => $now->format('Y-m-d H:i:s'),
            'activation_code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'activation_code' => $code,
            'activation_code_expires_at' => $expires,
        ]);
        if (!$ok) {
            throw new RuntimeException('Insert failed.');
        }
        return [
            'student_number' => $d['student_number'],
            'full_name' => $d['full_name'],
            'activation_code' => $code,
            'activation_code_expires_at' => $expires
        ];
    }

    public function index(): void
    {
        $this->admin(function () {
            $filters = [
                'search' => mb_substr(trim((string) ($_GET['search'] ?? '')), 0, 100),
                'account_status' => $_GET['account_status'] ?? '',
                'departure_reason' => $_GET['departure_reason'] ?? '',
                'activation_status' => $_GET['activation_status'] ?? '',
                'qr_status' => $_GET['qr_status'] ?? '',
                'course' => $_GET['course'] ?? '',
                'year_level' => $_GET['year_level'] ?? '',
                'section' => $_GET['section'] ?? '',
                'sort' => $_GET['sort'] ?? 'recent',
                'page' => $_GET['page'] ?? 1,
                'per_page' => $_GET['per_page'] ?? 10,
            ];
            $r = $this->StudentModel->get_students($filters);
            $this->api->respond([
                'success' => true,
                'data' => $r['students'],
                'pagination' => $r['pagination']
            ], 200);
        });
    }

    public function export_qr_batch(): void
    {
        $this->admin(function () {
            $filters = $this->input();
            $filters['activation_status'] = 'PENDING';
            $filters['account_status'] = 'ACTIVE';
            $filters['qr_status'] = 'ACTIVE';
            $result = $this->StudentModel->get_students($filters, true);
            if ($result['pagination']['total_records'] > 2000) {
                return $this->fail('Too many students. Narrow your filters.');
            }
            $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
            $students = [];
            foreach ($result['students'] as $student) {
                if (!empty($student['user_id']) || !preg_match('/^[a-f0-9]{64}$/i', (string) $student['qr_token']) || !preg_match('/^[a-f0-9]{12}$/i', (string) $student['activation_code']) || empty($student['activation_code_expires_at'])) {
                    continue;
                }
                try {
                    if (new DateTimeImmutable($student['activation_code_expires_at'], new DateTimeZone('Asia/Manila')) <= $now) {
                        continue;
                    }
                } catch (Throwable $e) {
                    continue;
                }
                $students[] = array_intersect_key($student, array_flip([
                    'student_number',
                    'full_name',
                    'qr_token',
                    'activation_code'
                ]));
            }
            if (count($students) > 500) {
                return $this->fail('More than 500 eligible students. Narrow your filters.');
            }
            if (!$students) {
                return $this->fail('No pending students with active QR tokens and unexpired activation codes match these filters.');
            }
            $this->api->respond([
                'success' => true,
                'data' => $students
            ], 200);
        });
    }

    public function summary(): void
    {
        $this->admin(function () {
            $this->api->respond([
                'success' => true,
                'data' => $this->StudentModel->get_summary()
            ], 200);
        });
    }

    public function filter_options(): void
    {
        $this->admin(function () {
            $this->api->respond([
                'success' => true,
                'data' => $this->StudentModel->get_filter_options()
            ], 200);
        });
    }

    public function create(): void
    {
        $this->admin(function () {
            $d = $this->validate($this->input());
            if (!$d) {
                return $this->fail('Invalid student details. Check number, name, section, course and year.');
            }
            if ($this->StudentModel->student_number_exists($d['student_number'])) {
                return $this->fail('Student number already exists.', 409);
            }
            if ($this->StudentModel->student_details_exist($d)) {
                return $this->fail('Possible duplicate student identity. Review the existing record.', 409);
            }
            try {
                $new = $this->createRecord($d);
                $this->api->respond([
                    'success' => true,
                    'message' => 'Student registered.',
                    'data' => $new
                ], 201);
            } catch (Throwable $e) {
                error_log('Student create: ' . $e->getMessage());
                $this->fail('Unable to register student. Check database columns and logs.', 500);
            }
        });
    }

    public function update(): void
    {
        $this->api->require_method('POST');
        $this->admin(function () {
            try {
                $input = $this->input();
                $auth = $this->api->require_jwt();
                $data = $this->StudentModel->update_students($input, (int) $auth['sub']);
                $this->api->respond([
                    'success' => true,
                    'message' => $input['preview'] ? 'Review the proposed changes.' : $data['count'] . ' student records updated.',
                    'data' => $data,
                ]);
            } catch (DomainException $e) {
                $this->fail($e->getMessage(), $e->getCode() ?: 422);
            } catch (Throwable $e) {
                error_log('Student batch update: ' . get_class($e));
                $this->fail('Unable to update students. Check that the student_updates migration has been applied.', 500);
            }
        });
    }

    public function matching_students(): void
    {
        $this->api->require_method('GET');
        $this->admin(function () {
            try {
                $ids = $this->StudentModel->matching_student_ids($_GET);
                if (count($ids) > 2000) {
                    throw new DomainException('Narrow the filters to at most 2,000 matching students.', 422);
                }
                $this->api->respond(['success' => true, 'data' => ['student_ids' => $ids, 'count' => count($ids)]]);
            } catch (DomainException $e) {
                $this->fail($e->getMessage(), $e->getCode() ?: 422);
            }
        });
    }

    public function profile(): void
    {
        $this->api->require_method('GET');
        $this->admin(function () {
            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $student = $id ? $this->StudentModel->profile_details((int) $id) : null;
            if (!$student) {
                return $this->fail('Student not found.', 404);
            }
            $this->api->respond(['success' => true, 'data' => $student]);
        });
    }

    public function change_history(): void
    {
        $this->api->require_method('GET');
        $this->admin(function () {
            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id || !$this->StudentModel->find_by_id((int) $id)) {
                return $this->fail('Student not found.', 404);
            }
            $this->api->respond(['success' => true, 'data' => $this->StudentModel->change_history((int) $id)]);
        });
    }

    public function delete(): void
    {
        $this->admin(function () {
            $v = $this->input();
            $id = filter_var($v['id'] ?? null, FILTER_VALIDATE_INT, [
                'options' => [
                    'min_range' => 1
                ]
            ]);
            if (!$id) {
                return $this->fail('Invalid student ID.');
            }
            $s = $this->StudentModel->find_by_id((int) $id);
            if (!$s) {
                return $this->fail('Student not found.', 404);
            }
            if (($s['activation_status'] ?? '') !== 'PENDING' || !empty($s['user_id'])) {
                return $this->fail('Activated or linked students cannot be deleted.', 409);
            }
            try {
                if (!$this->StudentModel->delete_pending_student((int) $id)) {
                    return $this->fail('Delete failed.', 500);
                }
                $this->api->respond([
                    'success' => true,
                    'message' => 'Pending student deleted.'
                ], 200);
            } catch (Throwable $e) {
                error_log('Student delete: ' . $e->getMessage());
                $this->fail('Unable to delete; related records may exist.', 409);
            }
        });
    }

    public function reset_code(): void
    {
        $this->admin(function () {
            $v = $this->input();
            $id = filter_var($v['id'] ?? null, FILTER_VALIDATE_INT, [
                'options' => [
                    'min_range' => 1
                ]
            ]);
            if (!$id) {
                return $this->fail('Invalid student ID.');
            }
            $s = $this->StudentModel->find_by_id((int) $id);
            if (!$s || ($s['activation_status'] ?? '') !== 'PENDING' || !empty($s['user_id'])) {
                return $this->fail('Only pending, unlinked students can receive a replacement code.', 409);
            }
            $code = strtoupper(bin2hex(random_bytes(6)));
            $expires = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->modify('+7 days')->format('Y-m-d H:i:s');
            if (!$this->StudentModel->replace_activation_code((int) $id, password_hash($code, PASSWORD_DEFAULT), $code, $expires)) {
                return $this->fail('Could not reset activation code.', 500);
            }
            $this->api->respond([
                'success' => true,
                'message' => 'Activation code reset. Keep it private.',
                'data' => [
                    'full_name' => $s['full_name'],
                    'student_number' => $s['student_number'],
                    'activation_code' => $code,
                    'activation_code_expires_at' => $expires
                ]
            ], 200);
        });
    }

    public function reveal_code(): void
    {
        $this->admin(function () {
            $v = $this->input();
            $id = filter_var($v['id'] ?? null, FILTER_VALIDATE_INT, [
                'options' => [
                    'min_range' => 1
                ]
            ]);
            if (!$id) {
                return $this->fail('Invalid student ID.');
            }
            $s = $this->StudentModel->find_by_id((int) $id);
            if (!$s || ($s['activation_status'] ?? '') !== 'PENDING' || empty($s['activation_code'])) {
                return $this->fail('No readable pending activation code. Reset the code if needed.', 404);
            }
            if (!empty($s['activation_code_expires_at']) && strtotime($s['activation_code_expires_at']) < time()) {
                return $this->fail('Activation code expired. Reset it first.', 409);
            }
            $this->api->respond([
                'success' => true,
                'data' => [
                    'full_name' => $s['full_name'],
                    'student_number' => $s['student_number'],
                    'activation_code' => $s['activation_code'],
                    'activation_code_expires_at' => $s['activation_code_expires_at']
                ]
            ], 200);
        });
    }

    public function qr_status(): void
    {
        $this->api->require_method('POST');
        $this->admin(function () {
            $v = $this->input();
            $id = filter_var($v['id'] ?? null, FILTER_VALIDATE_INT, [
                'options' => [
                    'min_range' => 1
                ]
            ]);
            $action = strtoupper(trim((string) ($v['action'] ?? '')));
            if (!$id || !in_array($action, [
                'REVOKE',
                'REGENERATE'
            ], true)) {
                return $this->fail('Invalid QR action.');
            }
            if (!$this->StudentModel->find_by_id((int) $id)) {
                return $this->fail('Student not found.', 404);
            }
            try {
                $auth = $this->api->require_jwt();
                $this->call->model('QrCredentialModel')->change((int) $id, (int) $auth['sub'], $action === 'REGENERATE');
            } catch (DomainException $error) {
                return $this->fail($error->getMessage(), $error->getCode() ?: 422);
            } catch (Throwable $error) {
                error_log('QR credentials: ' . get_class($error));
                return $this->fail('Unable to update QR credentials.', 500);
            }
            $this->api->respond([
                'success' => true,
                'message' => $action === 'REVOKE' ? 'QR token revoked.' : 'New QR token generated.'
            ], 200);
        });
    }

    public function qr_details(): void
    {
        $this->admin(function () {
            $v = $this->input();
            $id = filter_var($v['id'] ?? null, FILTER_VALIDATE_INT, [
                'options' => [
                    'min_range' => 1
                ]
            ]);
            if (!$id) {
                return $this->fail('Invalid student ID.');
            }
            $s = $this->StudentModel->find_by_id((int) $id);
            if (!$s || ($s['qr_token_status'] ?? '') !== 'ACTIVE' || empty($s['qr_token'])) {
                return $this->fail('No active QR token found.', 404);
            }
            $this->api->respond([
                'success' => true,
                'data' => [
                    'id' => (int) $s['id'],
                    'student_number' => $s['student_number'],
                    'full_name' => $s['full_name'],
                    'qr_token' => $s['qr_token']
                ]
            ], 200);
        });
    }

    public function bulk_import(): void
    {
        $this->admin(function () {
            $v = $this->input();
            $rows = $v['rows'] ?? null;
            if (!is_array($rows) || count($rows) > 500) {
                return $this->fail('Import must contain at most 500 student rows.');
            }
            $created = 0;
            $skipped = 0;
            $errors = [];
            $seenNumbers = [];
            $seenDetails = [];
            foreach ($rows as $i => $row) {
                $line = $i + 2;
                $d = is_array($row) ? $this->validate($row) : null;
                if (!$d) {
                    $skipped++;
                    $errors[] = "Row {$line}: invalid student details.";
                    continue;
                }
                $key = mb_strtolower(implode('|', [
                    $d['full_name'],
                    $d['section'],
                    $d['course'],
                    $d['year_level']
                ]));
                if (isset($seenNumbers[$d['student_number']]) || $this->StudentModel->student_number_exists($d['student_number'])) {
                    $skipped++;
                    $errors[] = "Row {$line}: duplicate student number.";
                    continue;
                }
                if (isset($seenDetails[$key]) || $this->StudentModel->student_details_exist($d)) {
                    $skipped++;
                    $errors[] = "Row {$line}: possible duplicate student identity.";
                    continue;
                }
                try {
                    $this->createRecord($d);
                    $seenNumbers[$d['student_number']] = true;
                    $seenDetails[$key] = true;
                    $created++;
                } catch (Throwable $e) {
                    error_log('Student import row ' . $line . ': ' . $e->getMessage());
                    $skipped++;
                    $errors[] = "Row {$line}: database insert failed.";
                }
            }
            $this->api->respond([
                'success' => true,
                'message' => 'Bulk import processed.',
                'data' => [
                    'created' => $created,
                    'skipped' => $skipped,
                    'errors' => array_slice($errors, 0, 30),
                    'additional_errors' => max(0, count($errors) - 30)
                ]
            ], 200);
        });
    }
}
