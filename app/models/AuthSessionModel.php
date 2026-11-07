<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AuthSessionModel extends Model
{
    public function rotate(string $refreshToken, int $userId, callable $issueTokens): array
    {
        $hash = hash_hmac('sha256', $refreshToken, config_item('refresh_token_key'));
        $this->db->transaction();
        try {
            $session = $this->db->raw('SELECT user_id FROM refresh_tokens WHERE token = ? AND expires_at > ? FOR UPDATE', [
                $hash,
                date('Y-m-d H:i:s')
            ])->fetch(PDO::FETCH_ASSOC);
            if (!$session || (int) $session['user_id'] !== $userId) {
                throw new DomainException('Session has ended.', 401);
            }
            $user = $this->db->table('users')
                ->select('id, username, email, role, organization_name, status')
                ->where('id', '=', $userId)
                ->get(PDO::FETCH_ASSOC);
            if (!$user || $user['status'] !== 'ACTIVE') {
                throw new DomainException('Account unavailable.', 403);
            }
            if ($user['role'] === 'STUDENT') {
                $this->call->model('StudentAccountModel');
                $this->StudentAccountModel->profile($userId);
            }
            $tokens = $issueTokens($user);
            $this->db->table('refresh_tokens')
                ->where('token', '=', $hash)
                ->delete();
            $this->db->commit();
            return [
                'tokens' => $tokens,
                'user' => array_intersect_key($user, array_flip([
                    'id',
                    'username',
                    'email',
                    'role',
                    'organization_name',
                ])),
            ];
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }
}
