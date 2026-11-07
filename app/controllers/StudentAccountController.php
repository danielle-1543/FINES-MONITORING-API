<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class StudentAccountController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->library('api');
        $this->call->model('StudentAccountModel');
    }

    private function activation_request(callable $work): void
    {
        header('Cache-Control: no-store');
        $this->api->require_method('POST');
        $this->api->rate_limit('student-activation:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 20, 600);
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
            $this->api->respond_error('Send application/json.', 415);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            $this->api->respond_error('Invalid JSON request.', 400);
        }
        try {
            $result = $work($data);
            $this->api->respond([
                'success' => true
            ] + $result);
        } catch (DomainException $e) {
            $this->api->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getCode() ?: 422);
        } catch (Throwable $e) {
            error_log('Student activation failed: ' . get_class($e));
            $this->api->respond_error('Unable to activate the account. Try again or contact the administrator.', 500);
        }
    }

    public function preview(): void
    {
        $this->activation_request(fn ($data) => [
            'data' => $this->StudentAccountModel->preview($data)
        ]);
    }

    public function identify(): void
    {
        $this->activation_request(fn ($data) => [
            'data' => $this->StudentAccountModel->identify($data)
        ]);
    }

    public function activate(): void
    {
        $this->activation_request(function ($data) {
            $this->StudentAccountModel->activate($data);
            return [
                'message' => 'Account activated. You can now log in with your username or email.'
            ];
        });
    }

    public function profile(): void
    {
        $this->student_response(false);
    }

    public function dashboard(): void
    {
        $this->student_response(true);
    }

    private function portal(string $method, callable $work): void
    {
        header('Cache-Control: private, no-store');
        $this->api->require_method($method);
        $auth = $this->api->require_jwt();
        if (($auth['type'] ?? '') !== 'access') {
            $this->api->respond_error('Access token required.', 401);
        }
        if (($auth['role'] ?? '') !== 'STUDENT') {
            $this->api->respond_error('Student access only.', 403);
        }
        try {
            $id = (int) $auth['sub'];
            $this->StudentAccountModel->require_session($id, (string) ($auth['jti'] ?? ''));
            $this->call->model('StudentPortalModel');
            $data = [];
            if ($method === 'POST') {
                $this->api->rate_limit('student-account-update:' . $id, 10, 600);
                if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
                    throw new DomainException('Send application/json.', 415);
                }
                $data = json_decode(file_get_contents('php://input'), true);
                if (!is_array($data)) {
                    throw new DomainException('Invalid JSON.', 400);
                }
            }
            $result = $work($id, $data);
            $this->api->respond([
                'success' => true
            ] + $result);
        } catch (DomainException $e) {
            $this->api->respond_error($e->getMessage(), $e->getCode() ?: 422);
        } catch (Throwable $e) {
            error_log('Student portal: ' . get_class($e));
            $this->api->respond_error('Unable to process your account request.', 500);
        }
    }

    public function account(): void
    {
        $this->portal('GET', fn ($id) => [
            'data' => $this->StudentPortalModel->account($id)
        ]);
    }

    public function qr(): void
    {
        $this->portal('GET', fn ($id) => [
            'data' => $this->StudentPortalModel->qr($id)
        ]);
    }

    public function history(): void
    {
        $this->portal('GET', fn ($id) => $this->StudentPortalModel->history($id, $_GET));
    }

    public function export_history(): void
    {
        $this->portal('GET', fn ($id) => $this->StudentPortalModel->export_history($id, $_GET));
    }

    public function update_account(): void
    {
        $this->portal('POST', fn ($id, $data) => [
            'data' => $this->StudentPortalModel->update_account($id, $data),
            'message' => 'Account details updated.'
        ]);
    }

    public function change_password(): void
    {
        $this->portal('POST', function ($id, $data) {
            $this->StudentPortalModel->change_password($id, $data);
            return [
                'message' => 'Password changed. Please log in again.',
                'force_logout' => true
            ];
        });
    }

    private function student_response(bool $dashboard): void
    {
        header('Cache-Control: no-store');
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        if (($auth['type'] ?? '') !== 'access') {
            $this->api->respond_error('An access token is required.', 401);
        }
        if (($auth['role'] ?? '') !== 'STUDENT') {
            $this->api->respond_error('Student access only.', 403);
        }
        try {
            $this->StudentAccountModel->require_session((int) $auth['sub'], (string) ($auth['jti'] ?? ''));
            $this->api->respond([
                'success' => true,
                'data' => $dashboard ? $this->StudentAccountModel->dashboard((int) $auth['sub']) : $this->StudentAccountModel->profile((int) $auth['sub'])
            ]);
        } catch (DomainException $e) {
            $this->api->respond_error($e->getMessage(), $e->getCode());
        }
    }
}
