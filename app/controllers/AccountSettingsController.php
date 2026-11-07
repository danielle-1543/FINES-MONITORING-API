<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AccountSettingsController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->library('api');
        $this->call->model('AccountSettingsModel');
    }

    private function run(string $method, callable $work): void
    {
        header('Cache-Control: private, no-store');
        $this->api->require_method($method);
        load_class('AuthMiddleware', 'middlewares');
        (new AuthMiddleware([
            'ADMIN',
            'ORGANIZATION'
        ]))->handle(function () use ($method, $work) {
            $auth = $this->api->require_jwt();
            try {
                $data = [];
                if ($method === 'POST') {
                    $this->api->rate_limit('account-settings:' . $auth['sub'], 20, 600);
                    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
                    if ($contentType !== 'application/json') {
                        throw new DomainException('Use application/json.', 415);
                    }
                    $data = json_decode(file_get_contents('php://input'), true);
                    if (!is_array($data)) {
                        throw new DomainException('Invalid JSON.', 400);
                    }
                }
                $this->api->respond([
                    'success' => true,
                ] + $work((int) $auth['sub'], $data));
            } catch (DomainException $e) {
                $this->api->respond_error($e->getMessage(), $e->getCode() ?: 422);
            } catch (Throwable $e) {
                error_log('Account settings: ' . get_class($e));
                $this->api->respond_error('Unable to update account.', 500);
            }
        });
    }

    public function account(): void
    {
        $this->run('GET', function ($id) {
            return [
                'data' => $this->AccountSettingsModel->account($id),
            ];
        });
    }

    public function update(): void
    {
        $this->run('POST', function ($id, $data) {
            return [
                'data' => $this->AccountSettingsModel->update_account($id, $data),
                'message' => 'Account details updated.',
            ];
        });
    }

    public function password(): void
    {
        $this->run('POST', function ($id, $data) {
            $this->AccountSettingsModel->change_password($id, $data);
            return [
                'message' => 'Password changed. Please log in again.',
                'force_logout' => true,
            ];
        });
    }
}
