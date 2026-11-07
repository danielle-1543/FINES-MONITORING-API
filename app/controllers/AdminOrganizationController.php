<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminOrganizationController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->library('api');
        $this->call->model('AdminOrganizationModel');
    }

    private function run(string $method, callable $work): void
    {
        header('Cache-Control: private, no-store');
        $this->api->require_method($method);
        load_class('AuthMiddleware', 'middlewares');
        (new AuthMiddleware([
            'ADMIN'
        ]))->handle(function () use ($method, $work) {
            try {
                $data = $_GET;
                if ($method === 'POST') {
                    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
                        throw new DomainException('Use application/json.', 415);
                    }
                    $data = json_decode(file_get_contents('php://input'), true);
                    if (!is_array($data)) {
                        throw new DomainException('Invalid JSON.', 400);
                    }
                }
                $this->api->respond([
                    'success' => true
                ] + $work($data));
            } catch (DomainException $e) {
                $this->api->respond_error($e->getMessage(), $e->getCode() ?: 422);
            } catch (Throwable $e) {
                error_log('Admin organizations: ' . get_class($e));
                $this->api->respond_error('Unable to process organization request.', 500);
            }
        });
    }

    public function index(): void
    {
        $this->run('GET', fn ($data) => $this->AdminOrganizationModel->listing($data));
    }

    public function export(): void
    {
        $this->run('GET', fn ($data) => $this->AdminOrganizationModel->listing($data, true));
    }

    public function create(): void
    {
        $this->run('POST', function ($data) {
            $this->AdminOrganizationModel->save($data, false);
            return [
                'message' => 'Organization account created.'
            ];
        });
    }

    public function update(): void
    {
        $this->run('POST', function ($data) {
            $this->AdminOrganizationModel->save($data, true);
            return [
                'message' => 'Organization account updated.'
            ];
        });
    }
}
