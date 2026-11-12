<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * Controller: AdminDashboardController
 *
 * Automatically generated via CLI.
 */

class AdminDashboardController extends Controller
{
    public function activity_analytics(): void
    {
        header('Cache-Control: private, no-store');
        $this->api->require_method('GET');
        load_class('AuthMiddleware', 'middlewares');
        (new AuthMiddleware(['ADMIN']))->handle(function () {
            $auth = $this->api->require_jwt();
            $this->api->rate_limit('admin-posthog-report:' . $auth['sub'], 30, 600);
            try {
                $this->api->respond([
                    'success' => true,
                    'data' => $this->AdminDashboardModel->activity_analytics($_GET),
                ]);
            } catch (DomainException $e) {
                $this->api->respond_error($e->getMessage(), $e->getCode() ?: 422);
            } catch (Throwable $e) {
                error_log('Admin analytics: ' . get_class($e));
                $this->api->respond_error('Unable to load system activity analytics.', 500);
            }
        });
    }

    public function overview(): void
    {
        header('Cache-Control: private, no-store');
        $this->api->require_method('GET');
        load_class('AuthMiddleware', 'middlewares');
        (new AuthMiddleware([
            'ADMIN'
        ]))->handle(function () {
            try {
                $this->api->respond([
                    'success' => true,
                    'data' => $this->AdminDashboardModel->overview($_GET)
                ]);
            } catch (DomainException $e) {
                $this->api->respond_error($e->getMessage(), $e->getCode() ?: 422);
            } catch (Throwable $e) {
                error_log('Admin dashboard: ' . get_class($e));
                $this->api->respond_error('Unable to load dashboard.', 500);
            }
        });
    }

    public function __construct()
    {
        parent::__construct();
        // Load the API response helper.
        $this->call->library('api');
        // Load the dashboard database model.
        $this->call->model('AdminDashboardModel');
    }

    public function account_stats()
    {
        // Prevent caching of account statistics.
        header('Cache-Control: no-store');
        // Reuse the existing authentication middleware.
        // The middleware checks the JWT and the current database role.
        load_class('AuthMiddleware', 'middlewares');
        $middleware = new AuthMiddleware([
            'ADMIN'
        ]);
        $middleware->handle(function () {
            // Retrieve the account statistics from MySQL.
            $counts = $this->AdminDashboardModel->get_activation_counts();
            // Return a JSON response to React.
            $this->api->respond([
                'success' => true,
                'data' => $counts,
            ], 200);
        });
    }
}
