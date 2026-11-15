<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
class EmailOtpController extends Controller
{
    public function __construct() { parent::__construct(); $this->call->library('api'); $this->call->model('EmailOtpModel'); }
    private function run(callable $work): void
    {
        header('Cache-Control: no-store');
        $this->api->require_method('POST');
        $this->api->rate_limit('email-otp:'.($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 20, 600);
        $data = json_decode(file_get_contents('php://input'), true);
        try {
            if (!is_array($data)) throw new DomainException('Invalid JSON.', 400);
            $this->api->respond(['success'=>true] + $work($data));
        } catch (DomainException $e) { $this->api->respond_error($e->getMessage(), $e->getCode() ?: 422); }
        catch (Throwable $e) { error_log('Email OTP failure: '.get_class($e)); $this->api->respond_error('Unable to process email verification.', 500); }
    }
    public function request(): void
    {
        $this->run(function ($data) {
            $purpose = $data['purpose'] ?? '';
            $email = $data['email'] ?? '';
            if (!is_string($email) || !is_string($purpose)) throw new DomainException('Invalid request.', 422);
            $id = null;
            if (in_array($purpose, ['email_change','password_change'], true)) {
                $auth = $this->api->require_jwt(); $id = (int)$auth['sub'];
                $user = $this->db->raw('SELECT email,password_hash,status FROM users WHERE id=?', [$id])->fetch(PDO::FETCH_ASSOC);
                if (!$user || $user['status'] !== 'ACTIVE' || !is_string($data['current_password'] ?? null) || !password_verify($data['current_password'], $user['password_hash'])) throw new DomainException('Current password is incorrect.', 403);
                if ($purpose === 'password_change') $email = $user['email'];
            } elseif ($purpose === 'signup') {
                $this->call->model('StudentAccountModel')->preview($data);
            } elseif ($purpose === 'password_reset') {
                $this->api->rate_limit('otp-reset:'.hash('sha256', strtolower(trim($email))), 3, 600);
                $user = $this->db->raw("SELECT id,email FROM users WHERE email=? AND status='ACTIVE'", [trim($email)])->fetch(PDO::FETCH_ASSOC);
                if (!$user) return ['challenge_id'=>bin2hex(random_bytes(32)), 'expires_in'=>600, 'resend_after'=>60];
                $id = (int)$user['id']; $email = $user['email'];
            } else throw new DomainException('Invalid verification purpose.', 422);
            return $this->EmailOtpModel->issue($email, $purpose, $id);
        });
    }
    public function verify(): void
    {
        $this->run(function ($data) {
            $user = $this->db->raw("SELECT id,email FROM users WHERE id=? AND status='ACTIVE'", [(int)($data['user_id'] ?? 0)])->fetch(PDO::FETCH_ASSOC);
            if (!$user) throw new DomainException('Invalid verification request.', 422);
            $this->EmailOtpModel->consume($data, 'verify_email', $user['email'], (int)$user['id']);
            $this->db->raw('UPDATE users SET email_verified_at=UTC_TIMESTAMP() WHERE id=? AND email=?', [$user['id'],$user['email']]);
            return ['message'=>'Email verified. Please sign in.'];
        });
    }
    public function reset(): void
    {
        $this->run(function ($data) {
            $password = $data['password'] ?? null;
            $this->call->model('UserModel')->validate_password($password);
            if ($password !== ($data['password_confirmation'] ?? null)) throw new DomainException('Passwords do not match.', 422);
            $email = $data['email'] ?? '';
            if (!is_string($email)) throw new DomainException('Invalid email.', 422);
            $user = $this->db->raw("SELECT id,email FROM users WHERE email=? AND status='ACTIVE'", [trim($email)])->fetch(PDO::FETCH_ASSOC);
            if (!$user) throw new DomainException('Invalid or expired code.', 422);
            $this->EmailOtpModel->consume($data, 'password_reset', $user['email'], (int)$user['id']);
            $this->db->transaction();
            try {
                $updated = $this->db->raw('UPDATE users SET password_hash=?,auth_version=auth_version+1,email_verified_at=UTC_TIMESTAMP() WHERE id=? AND email=?', [password_hash($password,PASSWORD_DEFAULT),$user['id'],$user['email']]);
                if ($updated->rowCount() !== 1) throw new DomainException('Account changed. Request a new code.',409);
                $this->db->raw('DELETE FROM refresh_tokens WHERE user_id=?', [$user['id']]);
                $this->db->commit();
            } catch (Throwable $e) { $this->db->roll_back(); throw $e; }
            return ['message'=>'Password reset. Sign in with your new password.'];
        });
    }
}
