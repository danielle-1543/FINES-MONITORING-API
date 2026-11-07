<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * Controller: AuthController
 *
 * Automatically generated via CLI.
 */

class AuthController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->library('api');
        // Use the existing users table through its shared model.
        $this->call->model('UserModel');
        // The API helper is enabled through app/config/api.php.
    }
    /**
     * Simple status response for the backend root.
     * Does not expose database or account information.
     */

    public function index()
    {
        $this->api->respond([
            'success' => true,
            'message' => 'Fines Monitoring API is running.',
        ], 200);
    }
    /**
     * Verify credentials and issue an access/refresh token pair.
     * Authentication now uses JWT, not PHP session login values.
     */

    public function logout()
    {
        $this->api->require_method('POST');
        header('Cache-Control: no-store');
        $data = json_decode(file_get_contents('php://input'), true);
        $token = is_array($data) ? ($data['refresh_token'] ?? null) : null;
        if (!is_string($token) || $token === '' || strlen($token) > 4096) {
            $this->api->respond_error('A refresh token is required.', 422);
            return;
        }
        $this->api->revoke_refresh_token($token);
        $this->api->respond([
            'success' => true,
            'message' => 'Logged out.'
        ], 200);
    }

    public function login()
    {
        $this->api->require_method('POST');
        header('Cache-Control: no-store');
        // Limit login requests to reduce automated password guessing.
        $this->api->rate_limit('login:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 10, 60);
        // Require a JSON request from React or the API tester.
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($contentType !== 'application/json') {
            $this->api->respond_error('Send the request as application/json.', 415);
        }
        // Read JSON directly so password characters remain unchanged.
        $data = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            $this->api->respond_error('Invalid JSON request.', 400);
        }
        $identifier = $data['identifier'] ?? '';
        $password = $data['password'] ?? '';
        if (!is_string($identifier) || !is_string($password)) {
            $this->api->respond_error('Username/email and password must be text.', 422);
        }
        $identifier = trim($identifier);
        // Do not trim passwords; spaces may be intentional.
        if ($identifier === '' || $password === '') {
            $this->api->respond_error('Enter your username or email and password.', 422);
        }
        $user = $this->UserModel->find_by_login($identifier);
        // Keep the same error for an unknown account or wrong password.
        if (empty($user) || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
            $this->api->respond_error('Invalid username, email, or password.', 401);
        }
        if (($user['status'] ?? '') !== 'ACTIVE') {
            $this->api->respond_error('Your account is inactive. Contact the administrator.', 403);
        }
        $allowedRoles = [
            'ADMIN',
            'ORGANIZATION',
            'STUDENT'
        ];
        if (!in_array($user['role'] ?? '', $allowedRoles, true)) {
            $this->api->respond_error('Invalid account role.', 403);
        }
        // LavaLust signs the access token and stores a hashed refresh token.
        if ($user['role'] === 'STUDENT') {
            $this->call->model('StudentAccountModel');
            try {
                $this->StudentAccountModel->profile((int) $user['id']);
            } catch (DomainException $e) {
                $this->api->respond_error($e->getMessage(), 403);
            }
        }
        // The role comes from the database, never from the submitted JSON.
        $tokens = $this->api->issue_tokens([
            'id' => (int) $user['id'],
            'role' => $user['role'],
        ]);
        if ($user['role'] === 'STUDENT') {
            $access = $this->api->validate_jwt($tokens['access_token']);
            $this->StudentAccountModel->link_session((int) $user['id'], $access['jti'], hash_hmac('sha256', $tokens['refresh_token'], config_item('refresh_token_key')));
        }
        // Send tokens and safe profile fields. Never expose password_hash.
        $this->api->respond([
            'success' => true,
            'message' => 'Login successful.',
            'user' => [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => $user['role'],
                'organization_name' => $user['organization_name'] ?? '',
            ],
            'tokens' => $tokens,
        ], 200);
    }

    public function refresh(): void
    {
        header('Cache-Control: no-store');
        $this->api->require_method('POST');
        $data = json_decode(file_get_contents('php://input'), true);
        $token = is_array($data) ? ($data['refresh_token'] ?? null) : null;
        if (!is_string($token) || strlen($token) > 4096) {
            $this->api->respond_error('Invalid session.', 401);
        }
        $payload = $this->api->validate_jwt($token, 'refresh');
        if (!$payload) {
            $this->api->respond_error('Session expired. Please log in again.', 401);
        }
        $this->call->model('AuthSessionModel');
        try {
            $result = $this->AuthSessionModel->rotate($token, (int) $payload['sub'], function (array $user): array {
                $tokens = $this->api->issue_tokens([
                    'id' => (int) $user['id'],
                    'role' => $user['role'],
                ]);
                if ($user['role'] === 'STUDENT') {
                    $this->call->model('StudentAccountModel');
                    $access = $this->api->validate_jwt($tokens['access_token']);
                    $this->StudentAccountModel->link_session((int) $user['id'], $access['jti'], hash_hmac('sha256', $tokens['refresh_token'], config_item('refresh_token_key')));
                }
                return $tokens;
            });
            $this->api->respond([
                'success' => true
            ] + $result);
        } catch (DomainException $error) {
            $this->api->respond_error($error->getMessage(), $error->getCode() ?: 401);
        } catch (Throwable $error) {
            error_log('Session refresh failed: ' . get_class($error));
            $this->api->respond_error('Unable to refresh the session.', 500);
        }
    }
}
