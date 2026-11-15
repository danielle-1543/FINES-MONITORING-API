<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class UserModel extends Model
{
    public const PASSWORD_REQUIREMENTS = 'Use at least 12 characters with an uppercase letter, a lowercase letter, a number, and a symbol (maximum 72 UTF-8 bytes).';

    public static function validate_password($password): void
    {
        if (!is_string($password) || strlen($password) > 72 || str_contains($password, "\0")
            || preg_match('//u', $password) !== 1 || preg_match_all('/./us', $password) < 12
            || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password)
            || !preg_match('/[0-9]/', $password) || !preg_match('/[^\p{L}\p{N}\s]/u', $password)) {
            throw new DomainException(self::PASSWORD_REQUIREMENTS, 422);
        }
    }

    // This model uses the existing users table.

    protected $table = 'users';
    protected $guarded = ['id', 'password_hash', 'role', 'status', 'auth_version'];

    public function __construct()
    {
        parent::__construct();
    }
    /**
     * Find an account using its username or email.
     * The login controller will verify its password and status.
     */

    public function find_by_login($identifier)
    {
        $user = $this->db->table($this->table)
            ->select('id, username, email, password_hash, role, status, organization_name, email_verified_at')
            ->where('username', $identifier)
            ->get();
        if (!empty($user)) {
            return $user;
        }
        return $this->db->table($this->table)
            ->select('id, username, email, password_hash, role, status, organization_name, email_verified_at')
            ->where('email', $identifier)
            ->get();
    }
    /**
     * Fetch the latest account details using a session's user ID.
     * Later, this lets us check whether the account is still active.
     */

    public function find_by_id($id)
    {
        return $this->db->table($this->table)
            ->select('id, username, email, role, status, organization_name, auth_version')
            ->where('id', (int) $id)
            ->get();
    }
}
