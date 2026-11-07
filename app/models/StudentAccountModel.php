<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class StudentAccountModel extends Model
{
    private function text(array $data, string $key): string
    {
        if (!is_string($data[$key] ?? null)) {
            throw new DomainException('Enter valid account details.', 422);
        }
        return $data[$key];
    }

    private function verify_pair(array $data, bool $lock = false): array
    {
        $token = trim($this->text($data, 'qr_token'));
        $code = strtoupper(trim($this->text($data, 'activation_code')));
        if (!preg_match('/^[a-f0-9]{64}$/i', $token) || !preg_match('/^[A-F0-9]{12}$/', $code)) {
            throw new DomainException('The QR token or activation code is invalid.', 422);
        }
        if ($lock) {
            $student = $this->db->raw('SELECT * FROM students WHERE qr_token = ? FOR UPDATE', [
                $token
            ])->fetch(PDO::FETCH_ASSOC);
        } else {
            $student = $this->db->table('students')
                ->where('qr_token', $token)
                ->get();
        }
        if (!$student || $student['activation_status'] !== 'PENDING' || !empty($student['user_id']) || $student['account_status'] !== 'ACTIVE' || $student['qr_token_status'] !== 'ACTIVE' || empty($student['activation_code_hash']) || !password_verify($code, $student['activation_code_hash'])) {
            throw new DomainException('The QR/code pair is invalid, already used, or unavailable for activation.', 403);
        }
        try {
            $expires = empty($student['activation_code_expires_at']) ? null : new DateTimeImmutable($student['activation_code_expires_at'], new DateTimeZone('Asia/Manila'));
        } catch (Throwable $e) {
            $expires = null;
        }
        if (!$expires || $expires <= new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'))) {
            throw new DomainException('Your activation code has expired. Ask the administrator for a replacement.', 409);
        }
        return $student;
    }

    public function preview(array $data): array
    {
        return $this->profile_fields($this->verify_pair($data));
    }

    public function identify(array $data): array
    {
        $token = trim($this->text($data, 'qr_token'));
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            throw new DomainException('Scan your assigned student QR code.', 422);
        }
        $student = $this->db->table('students')
            ->select('student_number,full_name,course,year_level,section')
            ->where('qr_token', '=', $token)
            ->where('activation_status', '=', 'PENDING')
            ->where_null('user_id')
            ->where('account_status', '=', 'ACTIVE')
            ->where('qr_token_status', '=', 'ACTIVE')
            ->get(PDO::FETCH_ASSOC);
        if (!$student) {
            throw new DomainException('This QR code is not available for activation.', 403);
        }
        return $student;
    }

    private function profile_fields(array $student): array
    {
        return array_intersect_key($student, array_flip([
            'student_number',
            'full_name',
            'course',
            'year_level',
            'section',
            'activation_status',
            'account_status',
            'activated_at'
        ]));
    }

    public function activate(array $data): void
    {
        $username = trim($this->text($data, 'username'));
        $email = strtolower(trim($this->text($data, 'email')));
        $password = $this->text($data, 'password');
        $confirmation = $this->text($data, 'password_confirmation');
        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
            throw new DomainException('Username must be 3–50 letters, numbers, dots, underscores or hyphens.', 422);
        }
        if (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('Enter a valid email address.', 422);
        }
        if (strlen($password) < 8 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new DomainException('Password must be 8–72 bytes long.', 422);
        }
        if ($password !== $confirmation) {
            throw new DomainException('Passwords do not match.', 422);
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->db->transaction();
        try {
            $student = $this->verify_pair($data, true);
            $exists = $this->db->table('users')
                ->select('id')
                ->in('username', [
                    $username,
                    $email
                ])
                ->in('email', [
                    $username,
                    $email
                ], '', 'OR')
                ->limit(1)
                ->get();
            if ($exists) {
                throw new DomainException('That username or email is already registered.', 409);
            }
            // Unique indexes resolve concurrent claims without leaving an orphan student account.
            $insert = $this->db->raw("INSERT IGNORE INTO users (username,email,password_hash,role,status) VALUES (?,?,?,'STUDENT','ACTIVE')", [
                $username,
                $email,
                $hash
            ]);
            if ($insert->rowCount() !== 1) {
                throw new DomainException('That username or email is already registered.', 409);
            }
            $userId = (int) $this->db->last_id();
            $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
            $updated = $this->db->table('students')
                ->where('id', '=', $student['id'])
                ->where_null('user_id')
                ->where('activation_status', '=', 'PENDING')
                ->update([
                    'user_id' => $userId,
                    'activation_status' => 'ACTIVATED',
                    'activated_at' => $now,
                    'activation_code_hash' => null,
                    'activation_code' => null,
                    'activation_code_encrypted' => null,
                    'activation_code_expires_at' => null
                ]);
            if ($updated !== 1) {
                throw new DomainException('This activation code has already been used.', 409);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    public function profile(int $userId): array
    {
        $student = $this->db->table('students s')
            ->select('s.student_number,s.full_name,s.course,s.year_level,s.section,s.account_status,s.activation_status,s.activated_at')
            ->join('users u', 'u.id=s.user_id')
            ->where('u.id', $userId)
            ->where('u.role', 'STUDENT')
            ->where('u.status', 'ACTIVE')
            ->where('s.account_status', 'ACTIVE')
            ->where('s.activation_status', 'ACTIVATED')
            ->get();
        if (!$student) {
            throw new DomainException('An active, activated student account is required.', 403);
        }
        return $this->profile_fields($student);
    }

    public function link_session(int $userId, string $accessJti, string $refreshHash): void
    {
        $updated = $this->db->table('refresh_tokens')
            ->where('user_id', '=', $userId)
            ->where('token', '=', $refreshHash)
            ->update([
                'access_jti' => $accessJti
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Unable to track student login session.');
        }
    }

    public function dashboard(int $userId): array
    {
        require_once __DIR__ . '/AttendanceModel.php';
        (new AttendanceModel())->finalize();
        $profile = $this->profile($userId);
        $student = $this->db->table('students')
            ->select('id,course')
            ->where('user_id', '=', $userId)
            ->get(PDO::FETCH_ASSOC);
        $id = (int) $student['id'];
        // Both historical violation-event scans and newer checkbox/standalone violations.
        $violationsSql = "SELECT CONCAT('scan-',r.id) AS id,e.name AS event_name,v.name AS violation_name,
            r.fine_amount,r.recorded_at,u.organization_name
            FROM org_scan_records r JOIN org_events e ON e.id=r.event_id
            JOIN users u ON u.id=e.org_user_id
            LEFT JOIN org_violation_types v ON v.id=e.violation_type_id
            WHERE r.student_id=? AND r.record_type='VIOLATION'
            UNION ALL SELECT CONCAT('violation-',r.id),COALESCE(e.name,'Standalone violation'),
            r.violation_name,r.fine_amount,r.recorded_at,u.organization_name
            FROM org_violation_records r JOIN users u ON u.id=r.org_user_id
            LEFT JOIN org_events e ON e.id=r.event_id WHERE r.student_id=?";
        $totals = $this->db->raw('SELECT COUNT(*) AS total_violations,COALESCE(SUM(fine_amount),0) AS unpaid_fines FROM (' . $violationsSql . ') violations', [
            $id,
            $id
        ])->fetch(PDO::FETCH_ASSOC);
        $violations = $this->db->raw('SELECT * FROM (' . $violationsSql . ') violations ORDER BY recorded_at DESC,id DESC LIMIT 5', [
            $id,
            $id
        ])->fetchAll(PDO::FETCH_ASSOC);
        $attendance = $this->db->table('org_scan_records r')
            ->select('r.id,e.name AS event_name,u.organization_name,r.recorded_at,r.attendance_status')
            ->join('org_events e', 'e.id=r.event_id')
            ->join('users u', 'u.id=e.org_user_id')
            ->where('r.student_id', $id)
            ->where('r.record_type', 'ATTENDANCE')
            ->order_by('r.recorded_at', 'DESC')
            ->order_by('r.id', 'DESC')
            ->limit(5)
            ->get_all();
        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
        require_once __DIR__ . '/ProgramCatalogModel.php';
        $catalog = (new ProgramCatalogModel())->catalog();
        $groups = [];
        foreach ($catalog['organization_course_groups'] as $group => $programs) {
            if (in_array(strtoupper(trim($student['course'])), $programs, true)) {
                $groups[] = $group;
            }
        }
        $groupSql = $groups ? 'UPPER(TRIM(u.organization_course)) IN (' . implode(',', array_fill(0, count($groups), '?')) . ')' : '1=0';
        $events = $this->db->raw("SELECT e.id,e.name,e.scan_starts_at,e.scan_ends_at,u.organization_name
            FROM org_events e JOIN users u ON u.id=e.org_user_id
            WHERE e.event_type='ATTENDANCE' AND e.scan_ends_at>=? AND u.role='ORGANIZATION' AND u.status='ACTIVE'
            AND (u.organization_scope='ALL' OR (u.organization_scope='COURSE' AND (
                EXISTS(SELECT 1 FROM organization_programs op WHERE op.org_user_id=u.id AND op.program=?)
                OR (NOT EXISTS(SELECT 1 FROM organization_programs op WHERE op.org_user_id=u.id) AND {$groupSql})
            )))
            ORDER BY e.scan_starts_at,e.id LIMIT 5", array_merge([
            $now,
            $student['course']
        ], $groups))->fetchAll(PDO::FETCH_ASSOC);
        foreach ($events as & $event) {
            $event['event_status'] = $event['scan_starts_at'] <= $now ? 'ONGOING' : 'UPCOMING';
        }
        unset($event);
        require_once __DIR__ . '/PaymentModel.php';
        $payments = (new PaymentModel())->overview($userId);
        $recent = array_slice(array_values(array_filter($payments['claims'], fn ($p) => $p['status'] === 'CONFIRMED')), 0, 5);
        foreach ($recent as & $payment) {
            $payment['recorded_at'] = $payment['reviewed_at'];
        }
        unset($payment);
        return [
            'profile' => $profile,
            'summary' => [
                'total_violations' => (int) $totals['total_violations'],
                'unpaid_fines' => $payments['summary']['outstanding'],
                'total_fines' => $payments['summary']['total_fines'],
                'paid_amount' => $payments['summary']['paid_amount']
            ],
            'recent_attendance' => $attendance,
            'recent_violations' => $violations,
            'recent_payments' => $recent,
            'payments_available' => true,
            'upcoming_events' => $events
        ];
    }

    public function require_session(int $userId, string $accessJti): void
    {
        $session = $this->db->table('refresh_tokens')
            ->select('id')
            ->where('user_id', '=', $userId)
            ->where('access_jti', '=', $accessJti)
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->limit(1)
            ->get(PDO::FETCH_COLUMN);
        if (!$session) {
            throw new DomainException('Your session has ended. Please log in again.', 401);
        }
    }
}
