<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AuthMiddleware
{
    private $allowedRoles;

    public function __construct(array $allowedRoles = [])
    {
        $this->allowedRoles = $allowedRoles;
    }

    public function handle(Closure $next)
    {
        $lava = lava_instance();
        $lava->call->library('api');
        $auth = $lava->api->require_jwt();
        if (($auth['type'] ?? '') !== 'access') {
            $lava->api->respond_error('An access token is required.', 401);
        }
        $user = $lava->db->raw('SELECT role,status FROM users WHERE id = ?', [
            (int) $auth['sub']
        ])->fetch(PDO::FETCH_ASSOC);
        if (!$user || $user['status'] !== 'ACTIVE') {
            $lava->api->respond_error('An active account is required.', 403);
        }
        if ($this->allowedRoles && !in_array($user['role'], $this->allowedRoles, true)) {
            $lava->api->respond_error('This account cannot access this resource.', 403);
        }
        return $next();
    }
}
