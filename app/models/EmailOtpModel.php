<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once dirname(__DIR__).'/libraries/BrevoMailer.php';
class EmailOtpModel extends Model
{
    protected function deliver(string $email, string $code): void { BrevoMailer::send($email, $code); }
    public function issue(string $email, string $purpose, ?int $userId = null): array
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new DomainException('Enter a valid email.', 422);
        $subject = hash('sha256', $purpose.'|'.($userId ?? 0).'|'.$email);
        $id = bin2hex(random_bytes(32));
        $code = sprintf('%06d', random_int(0, 999999));
        $now = time();
        $hash = password_hash($code, PASSWORD_DEFAULT);
        $this->db->raw('INSERT IGNORE INTO email_otps (id,subject_key,user_id,email,purpose,code_hash,expires_at,created_at) VALUES (?,?,?,?,?,?,?,?)', [$id,$subject,$userId,$email,$purpose,$hash,$now+600,$now]);
        $row = $this->db->raw('SELECT id FROM email_otps WHERE subject_key=?', [$subject])->fetch(PDO::FETCH_ASSOC);
        if ($row['id'] !== $id) {
            $updated = $this->db->raw('UPDATE email_otps SET id=?,code_hash=?,expires_at=?,created_at=?,attempts=0 WHERE subject_key=? AND created_at<=?', [$id,$hash,$now+600,$now,$subject,$now-60]);
            if ($updated->rowCount() !== 1) throw new DomainException('Wait 60 seconds before requesting another code.', 429);
        }
        try { $this->deliver($email, $code); }
        catch (Throwable $e) { $this->db->raw('DELETE FROM email_otps WHERE id=?', [$id]); throw $e; }
        return ['challenge_id'=>$id, 'expires_in'=>600, 'resend_after'=>60];
    }

    public function consume(array $data, string $purpose, string $email, ?int $userId = null): void
    {
        $id = $data['challenge_id'] ?? '';
        $code = $data['otp'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9]{64}$/D', $id) || !is_string($code) || !preg_match('/^[0-9]{6}$/D', $code)) throw new DomainException('Enter the six-digit email code.', 422);
        // Independent transaction preserves failed attempts when the account operation fails.
        $this->db->transaction();
        try {
            $row = $this->db->raw('SELECT * FROM email_otps WHERE id=? FOR UPDATE', [$id])->fetch(PDO::FETCH_ASSOC);
            $valid = $row && $row['purpose'] === $purpose && strtolower($row['email']) === strtolower(trim($email)) && (int)$row['user_id'] === (int)$userId && (int)$row['expires_at'] > time() && (int)$row['attempts'] < 5;
            $ok = $valid && password_verify($code, $row['code_hash']);
            if ($row) $this->db->raw($ok ? 'DELETE FROM email_otps WHERE id=?' : 'UPDATE email_otps SET attempts=attempts+1 WHERE id=?', [$id]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->roll_back(); throw $e; }
        if (!$ok) throw new DomainException('Invalid or expired code. Request a new code after five failed attempts.', 422);
    }

    public function protect(int $id, array &$data, bool $password): void
    {
        $user = $this->db->raw('SELECT email,password_hash,status FROM users WHERE id=?', [$id])->fetch(PDO::FETCH_ASSOC);
        if (!$user || $user['status'] !== 'ACTIVE' || !is_string($data['current_password'] ?? null) || !password_verify($data['current_password'], $user['password_hash'])) throw new DomainException('Current password is incorrect.', 403);
        $email = $data['email'] ?? $user['email'];
        if (!is_string($email)) throw new DomainException('Enter a valid email.', 422);
        if ($password || strcasecmp(trim($email), $user['email']) !== 0) $this->consume($data, $password ? 'password_change' : 'email_change', $password ? $user['email'] : $email, $id);
        unset($data['challenge_id'], $data['otp']);
    }
}
