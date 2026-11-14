<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class PaymentController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->library('api');
        $this->call->model('PaymentModel');
        $this->call->model('StudentAccountModel');
    }

    private function run(string $method, callable $work, ?string $role = null): void
    {
        header('Cache-Control: private, no-store');
        $this->api->require_method($method);
        $auth = $this->api->require_jwt();
        if (($auth['type'] ?? '') !== 'access') {
            $this->api->respond_error('Access token required.', 401);
        }
        if (!in_array($auth['role'], [
            'STUDENT',
            'ORGANIZATION'
        ], true) || ($role && $auth['role'] !== $role)) {
            $this->api->respond_error('Access denied.', 403);
        }
        try {
            if ($auth['role'] === 'STUDENT') {
                $this->StudentAccountModel->require_session((int) $auth['sub'], $auth['jti']);
                $this->StudentAccountModel->profile((int) $auth['sub']);
            } else {
                $this->call->model('OrgEventModel');
                $this->OrgEventModel->organization((int) $auth['sub']);
            }
            $data = [];
            if ($method === 'POST') {
                $type = strtolower(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]);
                if ($type === 'application/json') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    if (!is_array($data)) {
                        throw new DomainException('Invalid JSON.', 400);
                    }
                } elseif ($type === 'multipart/form-data') {
                    $data = $_POST;
                } else {
                    throw new DomainException('Use JSON or multipart form data.', 415);
                }
            }
            $result = $work((int) $auth['sub'], $data, $auth['role']);
            $this->api->respond([
                'success' => true
            ] + $result);
        } catch (DomainException $e) {
            $this->api->respond_error($e->getMessage(), $e->getCode() ?: 422);
        } catch (Throwable $e) {
            error_log('Payment API: ' . get_class($e));
            $this->api->respond_error('Unable to process the payment request.', 500);
        }
    }

    private function id($id): int
    {
        $value = filter_var($id, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1
            ]
        ]);
        if (!$value) {
            throw new DomainException('Invalid claim ID.', 422);
        }
        return $value;
    }

    public function overview(): void
    {
        $this->run('GET', fn ($id) => [
            'data' => $this->PaymentModel->overview($id)
        ], 'STUDENT');
    }

    public function xendit_checkout(): void
    {
        $this->run('POST', function ($id, $data) {
            $this->api->rate_limit('xendit-checkout:' . $id, 10, 600);
            return ['data' => $this->call->model('XenditPaymentModel')->checkout($id, $this->id($data['organization_id'] ?? null), $data)];
        }, 'STUDENT');
    }

    public function xendit_status(): void
    {
        $this->run('GET', function ($id) {
            $this->api->rate_limit('xendit-status:' . $id, 30, 600);
            return ['data' => $this->call->model('XenditPaymentModel')->status($id, $this->id($_GET['id'] ?? null))];
        }, 'STUDENT');
    }

    public function periods(): void
    {
        $this->run('GET', fn ($id) => [
            'data' => $this->call->model('PaymentPeriodModel')->periods($id)
        ], 'ORGANIZATION');
    }

    public function save_period(): void
    {
        $this->run('POST', function ($id, $data) {
            $this->call->model('PaymentPeriodModel')->save($id, $data);
            return ['message' => 'Payment schedule saved.'];
        }, 'ORGANIZATION');
    }

    public function submit(): void
    {
        $this->run('POST', function ($id, $data) {
            $this->api->rate_limit('payment-claim:' . $id, 20, 600);
            $proof = null;
            $path = null;
            try {
                if (isset($_FILES['proof']) && $_FILES['proof']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $file = $_FILES['proof'];
                    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) {
                        throw new DomainException('Upload a valid proof file up to 5 MB.', 422);
                    }
                    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
                    $allowed = [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'application/pdf' => 'pdf'
                    ];
                    if (!isset($allowed[$mime])) {
                        throw new DomainException('Proof must be a JPEG, PNG or PDF.', 422);
                    }
                    $dir = ROOT_DIR . 'runtime/payment_proofs';
                    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
                        throw new RuntimeException('Storage unavailable.');
                    }
                    $name = bin2hex(random_bytes(24)) . '.' . $allowed[$mime];
                    $path = $dir . '/' . $name;
                    if (!move_uploaded_file($file['tmp_name'], $path)) {
                        throw new RuntimeException('Upload unavailable.');
                    }
                    $proof = [
                        'file' => $name,
                        'mime' => $mime
                    ];
                }
                $this->PaymentModel->submit($id, $data, $proof);
            } catch (Throwable $e) {
                if ($path && is_file($path)) {
                    unlink($path);
                }
                throw $e;
            }
            return [
                'message' => 'Payment claim submitted for organization review.'
            ];
        }, 'STUDENT');
    }

    public function cancel(): void
    {
        $this->run('POST', function ($id, $data) {
            $this->PaymentModel->cancel($id, $this->id($data['id'] ?? null));
            return [
                'message' => 'Claim cancelled.'
            ];
        }, 'STUDENT');
    }

    public function claims(): void
    {
        $this->run('GET', fn ($id) => [
            'data' => $this->PaymentModel->organization_claims($id)
        ], 'ORGANIZATION');
    }

    public function fines(): void
    {
        $this->run('GET', fn ($id) => $this->PaymentModel->organization_fines($id, $_GET), 'ORGANIZATION');
    }

    public function student_record(): void
    {
        $this->run('GET', fn ($id) => [
            'data' => $this->call->model('StudentVoucherModel')->record($id, $this->id($_GET['student_id'] ?? null))
        ], 'ORGANIZATION');
    }

    public function add_voucher(): void
    {
        $this->run('POST', function ($id, $data) {
            $this->call->model('StudentVoucherModel')->save($id, $this->id($data['student_id'] ?? null), $data);
            return ['message' => 'Voucher / discount added.'];
        }, 'ORGANIZATION');
    }

    public function fines_export(): void
    {
        $this->run('GET', fn ($id) => $this->PaymentModel->organization_fines($id, $_GET, true), 'ORGANIZATION');
    }

    public function review(): void
    {
        $this->run('POST', function ($id, $data) {
            if (!is_string($data['decision'] ?? null) || !is_string($data['note'] ?? '')) {
                throw new DomainException('Invalid review.', 422);
            }
            $this->PaymentModel->review($id, $this->id($data['id'] ?? null), $data['decision'], $data['note'] ?? '');
            return [
                'message' => 'Payment review saved.'
            ];
        }, 'ORGANIZATION');
    }

    public function receipt(): void
    {
        $this->run('GET', function ($id, $data, $role) {
            $row = $this->PaymentModel->document($id, $role, $this->id($_GET['id'] ?? null), true);
            return [
                'data' => array_intersect_key($row, array_flip([
                    'receipt_number',
                    'academic_period_label',
                    'full_name',
                    'student_number',
                    'organization_name',
                    'amount',
                    'method',
                    'reference_number',
                    'submitted_at',
                    'reviewed_at',
                    'fine_key'
                ]))
            ];
        });
    }

    public function proof(): void
    {
        $this->run('GET', function ($id, $data, $role) {
            $row = $this->PaymentModel->document($id, $role, $this->id($_GET['id'] ?? null));
            if (!$row['proof_file'] || !preg_match('/^[a-f0-9]{48}\.(jpg|png|pdf)$/', $row['proof_file'])) {
                throw new DomainException('Proof not found.', 404);
            }
            $path = ROOT_DIR . 'runtime/payment_proofs/' . $row['proof_file'];
            if (!is_file($path)) {
                throw new DomainException('Proof not found.', 404);
            }
            header('Content-Type: ' . $row['proof_mime']);
            header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: attachment; filename="payment-proof-' . (int) $row['id'] . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
            readfile($path);
            exit;
        });
    }
}
