<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class UserModel extends Model
{
    // This model uses the existing users table.

    protected $table = 'users';

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
            ->where('username', $identifier)
            ->get();
        if (!empty($user)) {
            return $user;
        }
        return $this->db->table($this->table)
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
            ->where('id', (int) $id)
            ->get();
    }
    /**
     * Run a small read-only query to confirm the users table is accessible.
     * An empty table is valid; a failed query is not.
     */

    public function check_connection()
    {
        $result = $this->db->table($this->table)
            ->select('id')
            ->limit(1)
            ->get_all();
        return is_array($result);
    }
}
