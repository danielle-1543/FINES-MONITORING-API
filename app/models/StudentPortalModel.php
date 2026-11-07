<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class StudentPortalModel extends StudentAccountModel
{
    private function own(int $userId): array
    {
        $this->profile($userId);
        return $this->db->table('students')
            ->where('user_id', '=', $userId)
            ->get(PDO::FETCH_ASSOC);
    }

    public function account(int $userId): array
    {
        $profile = $this->profile($userId);
        $user = $this->db->table('users')
            ->select('username,email')
            ->where('id', '=', $userId)
            ->get(PDO::FETCH_ASSOC);
        return [
            'profile' => $profile,
            'account' => $user
        ];
    }

    public function qr(int $userId): array
    {
        $student = $this->own($userId);
        return [
            'profile' => $this->profile($userId),
            'qr_token_status' => $student['qr_token_status'],
            'qr_token' => $student['qr_token_status'] === 'ACTIVE' ? $student['qr_token'] : null
        ];
    }

    private function credentials(int $userId, array $data, array $allowed): array
    {
        if (array_diff(array_keys($data), $allowed)) {
            throw new DomainException('Only permitted account fields may be updated.', 422);
        }
        $user = $this->db->raw('SELECT * FROM users WHERE id=? FOR UPDATE', [
            $userId
        ])->fetch(PDO::FETCH_ASSOC);
        if (!$user || $user['role'] !== 'STUDENT' || $user['status'] !== 'ACTIVE') {
            throw new DomainException('Active student account required.', 403);
        }
        if (!is_string($data['current_password'] ?? null) || strlen($data['current_password']) > 72 || !password_verify($data['current_password'], $user['password_hash'])) {
            throw new DomainException('Current password is incorrect.', 403);
        }
        return $user;
    }

    public function update_account(int $userId, array $data): array
    {
        $this->own($userId);
        if (!is_string($data['username'] ?? null) || !is_string($data['email'] ?? null)) {
            throw new DomainException('Enter a username and email.', 422);
        }
        $username = trim($data['username']);
        $email = strtolower(trim($data['email']));
        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
            throw new DomainException('Username must be 3–50 letters, numbers, dots, underscores or hyphens.', 422);
        }
        if (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('Enter a valid email address.', 422);
        }
        $this->db->transaction();
        try {
            $user = $this->credentials($userId, $data, [
                'username',
                'email',
                'current_password'
            ]);
            if ($username !== $user['username'] || $email !== $user['email']) {
                if ($this->db->table('users')
                    ->select('id')
                    ->where('id', '<>', $userId)
                    ->grouped(function ($query) use ($username, $email) {
                        $query->where('username', $username)
                            ->or_where('email', $email);
                    })
                    ->limit(1)
                    ->get()) {
                    throw new DomainException('That username or email is already registered.', 409);
                }
                $updated = $this->db->raw('UPDATE IGNORE users SET username=?,email=? WHERE id=?', [
                    $username,
                    $email,
                    $userId
                ]);
                if ($updated->rowCount() !== 1) {
                    throw new DomainException('That username or email is already registered.', 409);
                }
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
        return $this->account($userId);
    }

    public function change_password(int $userId, array $data): void
    {
        $this->own($userId);
        $password = $data['password'] ?? null;
        $confirmation = $data['password_confirmation'] ?? null;
        if (!is_string($password) || strlen($password) < 8 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new DomainException('Password must be 8–72 bytes long.', 422);
        }
        if (!is_string($confirmation) || $password !== $confirmation) {
            throw new DomainException('Passwords do not match.', 422);
        }
        $this->db->transaction();
        try {
            $user = $this->credentials($userId, $data, [
                'current_password',
                'password',
                'password_confirmation'
            ]);
            if (password_verify($password, $user['password_hash'])) {
                throw new DomainException('Choose a different password from your current one.', 422);
            }
            $this->db->table('users')
                ->where('id', '=', $userId)
                ->update([
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT)
                ]);
            $this->db->table('refresh_tokens')
                ->where('user_id', '=', $userId)
                ->delete();
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    private function records_query(int $userId, array $filters): array
    {
        require_once __DIR__ . '/PaymentModel.php';
        $allocations = (new PaymentModel())->allocated_payments();
        require_once __DIR__ . '/AttendanceModel.php';
        (new AttendanceModel())->finalize();
        $student = $this->own($userId);
        $id = (int) $student['id'];
        $kind = $filters['kind'] ?? 'ATTENDANCE';
        if (!in_array($kind, [
            'ATTENDANCE',
            'VIOLATION'
        ], true)) {
            throw new DomainException('Choose attendance or violations.', 422);
        }
        if ($kind === 'ATTENDANCE') {
            $base = "SELECT CONCAT('scan-',r.id) id,e.name event_name,u.organization_name,r.recorded_at,r.attendance_status,
                '' violation_name,r.fine_amount,COALESCE(p.paid_amount,0) paid_amount,GREATEST(0,r.fine_amount-COALESCE(p.paid_amount,0)) outstanding
                FROM org_scan_records r JOIN org_events e ON e.id=r.event_id JOIN users u ON u.id=e.org_user_id
                LEFT JOIN (SELECT fine_key,SUM(amount) paid_amount FROM (" . $allocations . ") allocated WHERE student_id=? AND status='CONFIRMED' GROUP BY fine_key) p ON p.fine_key=CONCAT('scan-',r.id)
                WHERE r.student_id=? AND r.record_type='ATTENDANCE'";
            $params = [
                $id,
                $id
            ];
        } else {
            $base = "SELECT f.*,COALESCE(p.paid_amount,0) paid_amount,GREATEST(0,f.fine_amount-COALESCE(p.paid_amount,0)) outstanding FROM (
                SELECT CONCAT('scan-',r.id) id,e.name event_name,u.organization_name,r.recorded_at,'' attendance_status,v.name violation_name,r.fine_amount
                FROM org_scan_records r JOIN org_events e ON e.id=r.event_id JOIN users u ON u.id=e.org_user_id
                LEFT JOIN org_violation_types v ON v.id=e.violation_type_id WHERE r.student_id=? AND r.record_type='VIOLATION'
                UNION ALL SELECT CONCAT('violation-',r.id),COALESCE(e.name,'Standalone violation'),u.organization_name,r.recorded_at,'',r.violation_name,r.fine_amount
                FROM org_violation_records r JOIN users u ON u.id=r.org_user_id LEFT JOIN org_events e ON e.id=r.event_id WHERE r.student_id=?
                ) f LEFT JOIN (SELECT fine_key,SUM(amount) paid_amount FROM (" . $allocations . ") allocated WHERE student_id=? AND status='CONFIRMED' GROUP BY fine_key) p ON p.fine_key=f.id";
            $params = [
                $id,
                $id,
                $id
            ];
        }
        $where = [];
        $search = $filters['search'] ?? '';
        if (!is_string($search)) {
            throw new DomainException('Invalid search.', 422);
        }
        $search = mb_substr(trim($search), 0, 100);
        if ($search !== '') {
            $where[] = '(event_name LIKE ? OR organization_name LIKE ? OR violation_name LIKE ?)';
            array_push($params,...array_fill(0, 3, '%' . $search . '%'));
        }
        foreach ([
            'from',
            'to'
        ] as $key) {
            $value = $filters[$key] ?? '';
            if (!is_string($value)) {
                throw new DomainException('Invalid date filter.', 422);
            }
            if ($value === '') {
                continue;
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value) {
                throw new DomainException('Enter valid date filters.', 422);
            }
            $where[] = $key === 'from' ? 'recorded_at >= ?' : 'recorded_at < ?';
            $params[] = $key === 'from' ? $value . ' 00:00:00' : $date->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }
        if (!empty($filters['from']) && !empty($filters['to']) && $filters['from'] > $filters['to']) {
            throw new DomainException('The start date must be before the end date.', 422);
        }
        return [
            'SELECT * FROM (' . $base . ') history' . ($where ? ' WHERE ' . implode(' AND ', $where) : ''),
            $params
        ];
    }

    public function history(int $userId, array $filters): array
    {
        [$sql, $params] = $this->records_query($userId, $filters);
        $total = (int) $this->db->raw('SELECT COUNT(*) FROM (' . $sql . ') counted', $params)->fetchColumn();
        $pages = max(1, (int) ceil($total / 25));
        $page = min($pages, max(1, (int) ($filters['page'] ?? 1)));
        $offset = ($page - 1) * 25;
        $rows = $this->db->raw($sql . " ORDER BY recorded_at DESC,id DESC LIMIT 25 OFFSET {$offset}", $params)->fetchAll(PDO::FETCH_ASSOC);
        $totals = $this->db->raw('SELECT COALESCE(SUM(fine_amount),0) total_fines,COALESCE(SUM(paid_amount),0) paid_amount,COALESCE(SUM(outstanding),0) outstanding FROM (' . $sql . ') summed', $params)->fetch(PDO::FETCH_ASSOC);
        return [
            'data' => $rows,
            'summary' => $totals,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $pages,
                'total_records' => $total
            ]
        ];
    }

    public function export_history(int $userId, array $filters): array
    {
        [$sql, $params] = $this->records_query($userId, $filters);
        return [
            'data' => $this->db->raw($sql . ' ORDER BY recorded_at DESC,id DESC', $params)->fetchAll(PDO::FETCH_ASSOC),
            'columns' => [
                'event_name' => 'Event',
                'organization_name' => 'Organization',
                'recorded_at' => 'Recorded At (PHT)',
                'attendance_status' => 'Attendance Status',
                'violation_name' => 'Violation',
                'fine_amount' => 'Fine (PHP)',
                'paid_amount' => 'Paid (PHP)',
                'outstanding' => 'Outstanding (PHP)'
            ]
        ];
    }
}
