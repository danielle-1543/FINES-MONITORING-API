<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
class QrCredentialModel extends Model
{
    public function change(int $studentId, int $adminId, bool $regenerate): void
    {
        $this->db->transaction();
        try {
            $admin = $this->db->table('users')->select('username,role,status')->where('id', $adminId)->get();
            if (!$admin || $admin['role'] !== 'ADMIN' || $admin['status'] !== 'ACTIVE') {
                throw new DomainException('Administrator access required.', 403);
            }
            // Scans lock the same student row; the builder has no FOR UPDATE.
            $student = $this->db->raw('SELECT id,student_number,qr_token,qr_token_generated_at FROM students WHERE id=? FOR UPDATE', [$studentId])->fetch(PDO::FETCH_ASSOC);
            if (!$student) throw new DomainException('Student not found.', 404);
            $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
            if ($student['qr_token']) {
                $hash = hash('sha256', $student['qr_token']);
                $old = $this->db->table('student_qr_credentials')->where('token_hash', $hash)->get();
                $history = ['status' => $regenerate ? 'REPLACED' : 'REVOKED', 'changed_at' => $now, 'changed_by' => $adminId];
                if ($old) {
                    $this->db->table('student_qr_credentials')->where('token_hash', $hash)->update($history);
                } else {
                    $this->db->table('student_qr_credentials')->insert($history + ['token_hash' => $hash, 'student_id' => $studentId, 'generated_at' => $student['qr_token_generated_at']]);
                }
            }
            $values = ['qr_token_status' => 'REVOKED'];
            if ($regenerate) {
                do {
                    $token = bin2hex(random_bytes(32));
                    $hash = hash('sha256', $token);
                } while ($this->db->table('student_qr_credentials')->where('token_hash', $hash)->count()
                    || $this->db->table('students')->where('qr_token', $token)->count());
                $this->db->table('student_qr_credentials')->insert([
                    'token_hash' => $hash, 'student_id' => $studentId, 'status' => 'ACTIVE',
                    'generated_at' => $now, 'changed_at' => $now, 'changed_by' => $adminId
                ]);
                $values = ['qr_token' => $token, 'qr_token_status' => 'ACTIVE', 'qr_token_generated_at' => $now];
            }
            $this->db->table('students')->where('id', $studentId)->update($values);
            $this->db->table('activity_logs')->insert([
                'actor_id' => $adminId, 'actor_name' => $admin['username'], 'actor_role' => 'ADMIN',
                'action' => ($regenerate ? 'Regenerated QR for ' : 'Revoked QR for ') . $student['student_number'], 'created_at' => $now
            ]);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }
}
