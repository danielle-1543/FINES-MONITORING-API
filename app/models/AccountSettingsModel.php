<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AccountSettingsModel extends Model
{
    private function user(int $id, bool $lock = false): array
    {
        if ($lock) {
            $row = $this->db->raw("SELECT id, username, email, role, organization_name, password_hash, auth_version, status FROM users WHERE id=? AND role IN ('ADMIN','ORGANIZATION') AND status='ACTIVE' FOR UPDATE", [
                $id
            ])->fetch(PDO::FETCH_ASSOC);
        } else {
            $row = $this->db->table('users')
                ->select('id, username, email, role, organization_name, password_hash, auth_version, status')
                ->where('id', $id)
                ->in('role', [
                    'ADMIN',
                    'ORGANIZATION'
                ])
                ->where('status', 'ACTIVE')
                ->get();
        }
        if (!$row) {
            throw new DomainException('An active admin or organization account is required.', 403);
        }
        return $row;
    }

    public function account(int $id): array
    {
        $row = $this->user($id);
        $account = array_intersect_key($row, array_flip([
            'id',
            'username',
            'email',
            'role',
            'organization_name'
        ]));
        $programs = null;
        if ($row['role'] === 'ORGANIZATION') {
            $programs = $this->call->model('OrgEventModel')->organization($id)['allowed_courses'];
        }
        return [
            'account' => $account,
            'programs' => $programs
        ];
    }

    private function verify(array $user, array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed)) {
            throw new DomainException('Unsupported account field.', 422);
        }
        if (!is_string($data['current_password'] ?? null) || strlen($data['current_password']) > 72 || !password_verify($data['current_password'], $user['password_hash'])) {
            throw new DomainException('Current password is incorrect.', 403);
        }
    }

    public function update_account(int $id, array $data): array
    {
        $username = $data['username'] ?? null;
        $email = $data['email'] ?? null;
        if (!is_string($username) || !is_string($email)) {
            throw new DomainException('Enter a username and email.', 422);
        }
        $username = trim($username);
        $email = trim($email);
        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username) || strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('Enter a valid username and email.', 422);
        }
        $this->db->transaction();
        try {
            $user = $this->user($id, true);
            $this->verify($user, $data, [
                'username',
                'email',
                'current_password',
                'organization_name'
            ]);
            $name = $user['organization_name'];
            if (array_key_exists('organization_name', $data)) {
                if ($user['role'] !== 'ORGANIZATION' || !is_string($data['organization_name']) || trim($data['organization_name']) === '' || mb_strlen($data['organization_name']) > 150) {
                    throw new DomainException('Invalid organization name.', 422);
                }
                $name = trim($data['organization_name']);
            }
            if ($this->db->table('users')
                ->select('id')
                ->grouped(function ($query) use ($username, $email) {
                    $query->where('username', $username)
                        ->or_where('email', $email);
                })
                ->where('id', '<>', $id)
                ->limit(1)
                ->get()) {
                throw new DomainException('Username or email is already used.', 409);
            }
            $this->db->table('users')
                ->where('id', '=', $id)
                ->update([
                    'username' => $username,
                    'email' => $email,
                    'organization_name' => $name
                ]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
                throw new DomainException('Username or email is already used.', 409);
            }
            throw $e;
        }
        return $this->account($id);
    }

    public function change_password(int $id, array $data): void
    {
        $password = $data['password'] ?? null;
        if (!is_string($password) || strlen($password) < 8 || strlen($password) > 72 || str_contains($password, "\0") || $password !== ($data['password_confirmation'] ?? null)) {
            throw new DomainException('Enter matching passwords, 8–72 bytes long.', 422);
        }
        $this->db->transaction();
        try {
            $user = $this->user($id, true);
            $this->verify($user, $data, [
                'current_password',
                'password',
                'password_confirmation'
            ]);
            if (password_verify($password, $user['password_hash'])) {
                throw new DomainException('Choose a different password.', 422);
            }
            $this->db->table('users')
                ->where('id', $id)
                ->update([
                    'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                    'auth_version' => (int) $user['auth_version'] + 1,
                ]);
            $this->db->table('refresh_tokens')
                ->where('user_id', '=', $id)
                ->delete();
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }
}
