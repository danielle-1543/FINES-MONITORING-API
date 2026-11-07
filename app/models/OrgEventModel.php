<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class OrgEventModel extends Model
{
    private function text(array $data, string $key): string
    {
        if (!is_string($data[$key] ?? '')) {
            throw new DomainException('Invalid ' . $key . '.', 422);
        }
        return trim($data[$key] ?? '');
    }

    private function id($value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1
            ]
        ]);
        if (!$id) {
            throw new DomainException('Invalid record ID.', 422);
        }
        return (int) $id;
    }

    public function organization(int $orgId): array
    {
        $user = $this->db->table('users')
            ->where('id', $orgId)
            ->get();
        if (!$user || $user['role'] !== 'ORGANIZATION' || $user['status'] !== 'ACTIVE') {
            throw new DomainException('An active organization account is required.', 403);
        }
        require_once __DIR__ . '/ProgramCatalogModel.php';
        $catalog = (new ProgramCatalogModel())->catalog();
        $selected = $this->db->table('organization_programs')
            ->select('program')
            ->where('org_user_id', '=', $orgId)
            ->order_by('program', 'ASC')
            ->get_all(PDO::FETCH_COLUMN);
        $user['allowed_courses'] = ($user['organization_scope'] ?? '') === 'ALL' ? null : ($selected ?: ($catalog['organization_course_groups'][$user['organization_course'] ?? ''] ?? []));
        if ($user['allowed_courses'] === []) {
            throw new DomainException('Organization course access has not been configured.', 403);
        }
        return $user;
    }

    private function course_scope(int $orgId, string $alias, array & $params): string
    {
        $courses = $this->organization($orgId)['allowed_courses'];
        if ($courses === null) {
            return '1=1';
        }
        array_push($params,...$courses);
        return 'UPPER(TRIM(' . $alias . '.course)) IN (' . implode(',', array_fill(0, count($courses), '?')) . ')';
    }

    public function scope_info(int $orgId): array
    {
        $org = $this->organization($orgId);
        return [
            'all_courses' => $org['allowed_courses'] === null,
            'courses' => $org['allowed_courses'] ?? []
        ];
    }

    private function write(int $orgId, callable $work)
    {
        $this->db->transaction();
        try {
            // Serialize organization writes, including duplicate type and scan checks.
            $user = $this->db->raw('SELECT role, status FROM users WHERE id = ? FOR UPDATE', [
                $orgId
            ])->fetch(PDO::FETCH_ASSOC);
            if (!$user || $user['role'] !== 'ORGANIZATION' || $user['status'] !== 'ACTIVE') {
                throw new DomainException('An active organization account is required.', 403);
            }
            $this->organization($orgId);
            $result = $work();
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    public function violation_types(int $orgId): array
    {
        return $this->db->table('org_violation_types')
            ->where('org_user_id', $orgId)
            ->order_by('name', 'ASC')
            ->get_all();
    }

    private function violation_type(int $orgId, int $id): array
    {
        $type = $this->db->table('org_violation_types')
            ->where('org_user_id', $orgId)
            ->where('id', $id)
            ->get();
        if (!$type) {
            throw new DomainException('Select a violation type belonging to your organization.', 422);
        }
        return $type;
    }

    public function create_type(int $orgId, array $data): void
    {
        $name = $this->text($data, 'name');
        $amount = $this->text($data, 'fine_amount');
        if ($name === '' || mb_strlen($name) > 120 || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $amount)) {
            throw new DomainException('Enter a violation name and a valid nonnegative fine amount.', 422);
        }
        $this->write($orgId, function () use ($orgId, $name, $amount) {
            if ($this->db->table('org_violation_types')
                ->where('org_user_id', $orgId)
                ->where('name', $name)
                ->get()) {
                throw new DomainException('This violation type already exists.', 409);
            }
            $this->db->table('org_violation_types')
                ->insert([
                    'org_user_id' => $orgId,
                    'name' => $name,
                    'fine_amount' => $amount
                ]);
        });
    }

    public function events(int $orgId): array
    {
        return $this->db->raw('SELECT e.*, v.name AS violation_name, v.fine_amount,
                (SELECT COUNT(*) FROM org_scan_records r WHERE r.event_id = e.id) AS scan_count
             FROM org_events e LEFT JOIN org_violation_types v ON v.id = e.violation_type_id AND v.org_user_id = e.org_user_id
             WHERE e.org_user_id = ? ORDER BY e.id DESC', [
            $orgId
        ])->fetchAll(PDO::FETCH_ASSOC);
    }

    private function event(int $orgId, int $id, bool $lock = false): array
    {
        if ($lock) {
            $event = $this->db->raw('SELECT * FROM org_events WHERE org_user_id = ? AND id = ? FOR UPDATE', [
                $orgId,
                $id
            ])->fetch(PDO::FETCH_ASSOC);
        } else {
            $event = $this->db->table('org_events')
                ->where('org_user_id', $orgId)
                ->where('id', $id)
                ->get();
        }
        if (!$event) {
            throw new DomainException('Event not found.', 404);
        }
        return $event;
    }

    private function datetime(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('Asia/Manila'));
        if (!$date || $date->format('Y-m-d\TH:i') !== $value) {
            throw new DomainException('Enter valid scanning start and end times.', 422);
        }
        return $date->format('Y-m-d H:i:s');
    }

    private function event_data(int $orgId, array $data): array
    {
        $name = $this->text($data, 'name');
        $type = $this->text($data, 'event_type');
        $start = $this->datetime($this->text($data, 'scan_starts_at'));
        $end = $this->datetime($this->text($data, 'scan_ends_at'));
        if ($name === '' || mb_strlen($name) > 150 || !in_array($type, [
            'ATTENDANCE',
            'VIOLATION'
        ], true) || $start >= $end) {
            throw new DomainException('Enter an event name, type, and an end time after the start time.', 422);
        }
        $violationId = null;
        if ($type === 'VIOLATION') {
            $violationId = $this->id($data['violation_type_id'] ?? null);
            $this->violation_type($orgId, $violationId);
        }
        return [
            'name' => $name,
            'event_type' => $type,
            'violation_type_id' => $violationId,
            'scan_starts_at' => $start,
            'scan_ends_at' => $end
        ];
    }

    public function create_event(int $orgId, array $data): void
    {
        $this->write($orgId, function () use ($orgId, $data) {
            $values = $this->event_data($orgId, $data);
            $values['org_user_id'] = $orgId;
            $values['session_open'] = 1;
            // Legacy column; scanning is determined only by the time window.
            $values['absence_enabled'] = $values['event_type'] === 'ATTENDANCE' ? 1 : 0;
            $this->db->table('org_events')
                ->insert($values);
            $eventId = (int) $this->db->last_id();
            if ($values['absence_enabled']) {
                require_once __DIR__ . '/AttendanceModel.php';
                (new AttendanceModel())->snapshot($eventId, $orgId);
            }
        });
    }

    public function edit_event(int $orgId, array $data): void
    {
        $id = $this->id($data['id'] ?? null);
        $this->write($orgId, function () use ($orgId, $id, $data) {
            $this->event($orgId, $id, true);
            if ($this->db->table('org_scan_records')
                ->where('event_id', $id)
                ->count()) {
                throw new DomainException('Events with recorded scans cannot be edited.', 409);
            }
            $values = $this->event_data($orgId, $data);
            $this->db->table('org_events')
                ->where('org_user_id', $orgId)
                ->where('id', $id)
                ->update($values);
            $this->db->table('org_event_roster')
                ->where('event_id', '=', $id)
                ->delete();
            $this->db->table('org_events')
                ->where('id', '=', $id)
                ->update([
                    'absence_enabled' => $values['event_type'] === 'ATTENDANCE' ? 1 : 0,
                    'attendance_finalized_at' => null
                ]);
            if ($values['event_type'] === 'ATTENDANCE') {
                require_once __DIR__ . '/AttendanceModel.php';
                (new AttendanceModel())->snapshot($id, $orgId);
            }
        });
    }

    private function records_query(int $orgId, array $filters): array
    {
        $params = [
            $orgId
        ];
        $where = 'e.org_user_id = ? AND ' . $this->course_scope($orgId, 's', $params);
        if (!empty($filters['event_id'])) {
            $id = $this->id($filters['event_id']);
            $this->event($orgId, $id);
            $where .= ' AND r.event_id = ?';
            $params[] = $id;
        }
        if (!empty($filters['record_type'])) {
            if (!in_array($filters['record_type'], [
                'ATTENDANCE',
                'VIOLATION'
            ], true)) {
                throw new DomainException('Invalid record type.', 422);
            }
        }
        $kind = $filters['record_type'] ?? '';
        if ($kind !== '') {
            $where .= ' AND r.record_type = ?';
            $params[] = $kind;
        }
        $extraNames = $kind === 'VIOLATION' ? "''" : "COALESCE((SELECT GROUP_CONCAT(x.violation_name ORDER BY x.violation_name SEPARATOR ', ') FROM org_violation_records x WHERE x.scan_record_id=r.id), '')";
        $totalFine = $kind === 'VIOLATION' ? 'r.fine_amount' : 'r.fine_amount + COALESCE((SELECT SUM(x.fine_amount) FROM org_violation_records x WHERE x.scan_record_id=r.id),0)';
        $sql = "SELECT CONCAT('scan-',r.id) AS id, r.event_id, r.record_type, r.fine_amount, r.recorded_at,
            s.student_number, s.full_name, s.section, s.course, s.year_level,
            e.name AS event_name, v.name AS violation_name,
            {$extraNames} AS additional_violations, {$totalFine} AS total_fine, CASE WHEN r.record_type='ATTENDANCE' THEN r.attendance_status ELSE '' END AS attendance_status
            FROM org_scan_records r JOIN org_events e ON e.id = r.event_id
            JOIN students s ON s.id = r.student_id
            LEFT JOIN org_violation_types v ON v.id = e.violation_type_id AND v.org_user_id = e.org_user_id
            WHERE {$where}";
        if ($kind === 'VIOLATION') {
            $more = [
                $orgId
            ];
            $extraWhere = 'x.org_user_id = ? AND ' . $this->course_scope($orgId, 's', $more);
            if (!empty($filters['event_id'])) {
                $extraWhere .= ' AND x.event_id = ?';
                $more[] = (int) $filters['event_id'];
            }
            $sql .= " UNION ALL SELECT CONCAT('violation-',x.id), x.event_id, 'VIOLATION', x.fine_amount, x.recorded_at,
                s.student_number,s.full_name,s.section,s.course,s.year_level,
                COALESCE(e.name,'Standalone violation'),x.violation_name,'',x.fine_amount,''
                FROM org_violation_records x JOIN students s ON s.id=x.student_id
                LEFT JOIN org_events e ON e.id=x.event_id WHERE {$extraWhere}";
            array_push($params,...$more);
        }
        return [
            $sql,
            $params
        ];
    }

    private function sorted(): string
    {
        return ' ORDER BY course ASC, CAST(year_level AS UNSIGNED) ASC, year_level ASC, section ASC, full_name ASC, student_number ASC, recorded_at DESC, id ASC';
    }

    public function records(int $orgId, array $filters = [], int $limit = 100): array
    {
        [$sql, $params] = $this->records_query($orgId, $filters);
        $limit = max(1, min(100, $limit));
        return $this->db->raw('SELECT * FROM (' . $sql . ') records' . $this->sorted() . ' LIMIT ' . $limit, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function page_query(string $sql, array $params, array $filters, string $order): array
    {
        $total = (int) $this->db->raw('SELECT COUNT(*) FROM (' . $sql . ') counted', $params)->fetchColumn();
        $pages = max(1, (int) ceil($total / 50));
        $page = min($pages, max(1, (int) ($filters['page'] ?? 1)));
        $offset = ($page - 1) * 50;
        return [
            'data' => $this->db->raw('SELECT * FROM (' . $sql . ') records' . $order . " LIMIT 50 OFFSET {$offset}", $params)->fetchAll(PDO::FETCH_ASSOC),
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $pages,
                'total_records' => $total,
                'per_page' => 50
            ]
        ];
    }

    public function records_page(int $orgId, array $filters): array
    {
        [$sql, $params] = $this->records_query($orgId, $filters);
        return $this->page_query($sql, $params, $filters, $this->sorted());
    }

    public function export_records(int $orgId, array $filters)
    {
        [$sql, $params] = $this->records_query($orgId, $filters);
        return $this->db->raw('SELECT * FROM (' . $sql . ') records' . $this->sorted(), $params);
    }

    private function students_query(int $orgId, array $filters): array
    {
        $params = [];
        $where = $this->course_scope($orgId, 's', $params);
        $search = mb_substr($this->text($filters, 'search'), 0, 100);
        if ($search !== '') {
            $where .= ' AND (s.student_number LIKE ? OR s.full_name LIKE ? OR s.section LIKE ?)';
            array_push($params,...array_fill(0, 3, '%' . $search . '%'));
        }
        return [
            "SELECT s.student_number,s.full_name,s.course,s.year_level,s.section,s.account_status,s.activation_status FROM students s WHERE {$where}",
            $params
        ];
    }

    public function students_page(int $orgId, array $filters): array
    {
        [$sql, $params] = $this->students_query($orgId, $filters);
        return $this->page_query($sql, $params, $filters, ' ORDER BY course,CAST(year_level AS UNSIGNED),year_level,section,full_name,student_number');
    }

    public function export_students(int $orgId, array $filters)
    {
        [$sql, $params] = $this->students_query($orgId, $filters);
        return $this->db->raw($sql . ' ORDER BY course,CAST(year_level AS UNSIGNED),year_level,section,full_name,student_number', $params);
    }

    private function student_for_scan(int $orgId, string $token, bool $lock): array
    {
        $params = [
            $token
        ];
        $scope = $this->course_scope($orgId, 's', $params);
        $student = $this->db->raw('SELECT s.id,s.student_number,s.full_name,s.section,s.course,s.year_level,
            s.account_status,s.activation_status,s.qr_token_status FROM students s WHERE s.qr_token = ? AND ' . $scope . ($lock ? ' FOR UPDATE' : ''), $params)->fetch(PDO::FETCH_ASSOC);
        if (!$student || $student['account_status'] !== 'ACTIVE' || $student['activation_status'] !== 'ACTIVATED' || $student['qr_token_status'] !== 'ACTIVE') {
            throw new DomainException('Student is outside your organization or the account/QR is not active and activated.', 403);
        }
        return $student;
    }

    private function scan_context(int $orgId, array $data, bool $lock): array
    {
        $id = $this->id($data['event_id'] ?? null);
        $token = $this->text($data, 'qr_token');
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            throw new DomainException('Invalid QR token.', 422);
        }
        $event = $this->event($orgId, $id, $lock);
        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
        if ($now < $event['scan_starts_at'] || $now > $event['scan_ends_at']) {
            throw new DomainException('Scanning is outside the allowed time window.', 403);
        }
        $student = $this->student_for_scan($orgId, $token, $lock);
        if ($this->db->table('org_scan_records')
            ->where('event_id', $id)
            ->where('student_id', $student['id'])
            ->count()) {
            throw new DomainException('This student was already recorded for this event.', 409);
        }
        $fine = $event['event_type'] === 'VIOLATION' ? (string) $this->violation_type($orgId, (int) $event['violation_type_id'])['fine_amount'] : '0.00';
        return [
            $event,
            $student,
            $fine,
            $now
        ];
    }

    public function preview_scan(int $orgId, array $data): array
    {
        [$event, $student, $fine] = $this->scan_context($orgId, $data, false);
        return [
            'student' => array_intersect_key($student, array_flip([
                'student_number',
                'full_name',
                'section',
                'course',
                'year_level'
            ])),
            'event_type' => $event['event_type'],
            'event_name' => $event['name'],
            'fine_amount' => $fine,
            'violation_types' => $this->violation_types($orgId),
            'default_violation_ids' => $event['event_type'] === 'VIOLATION' ? [
                (int) $event['violation_type_id']
            ] : []
        ];
    }

    private function selected_types(int $orgId, array $data): array
    {
        $ids = $data['violation_type_ids'] ?? [];
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 100) {
            throw new DomainException('Select valid violation types.', 422);
        }
        $types = [];
        foreach ($ids as $value) {
            $id = $this->id($value);
            if (isset($types[$id])) {
                throw new DomainException('A violation was selected more than once.', 422);
            }
            $types[$id] = $this->violation_type($orgId, $id);
        }
        return $types;
    }

    private function save_violations(int $orgId, int $studentId, array $types, string $now, ?int $eventId = null, ?int $scanId = null): void
    {
        foreach ($types as $type) {
            $this->db->table('org_violation_records')
                ->insert([
                    'org_user_id' => $orgId,
                    'student_id' => $studentId,
                    'event_id' => $eventId,
                    'scan_record_id' => $scanId,
                    'violation_type_id' => $type['id'],
                    'violation_name' => $type['name'],
                    'fine_amount' => $type['fine_amount'],
                    'recorded_by' => $orgId,
                    'recorded_at' => $now,
                ]);
        }
    }

    public function record_scan(int $orgId, array $data): void
    {
        $this->write($orgId, function () use ($orgId, $data) {
            // Recheck status, window, ownership and duplicates at confirmation time.
            [$event, $student, $fine, $now] = $this->scan_context($orgId, $data, true);
            $types = $this->selected_types($orgId, $data);
            if ($event['event_type'] === 'VIOLATION')
                unset($types[(int) $event['violation_type_id']]);
            $this->db->table('org_scan_records')
                ->insert([
                    'event_id' => $event['id'],
                    'student_id' => $student['id'],
                    'recorded_by' => $orgId,
                    'record_type' => $event['event_type'],
                    'fine_amount' => $fine,
                    'recorded_at' => $now,
                ]);
            $scanId = (int) $this->db->last_id();
            $this->save_violations($orgId, (int) $student['id'], $types, $now, (int) $event['id'], $scanId);
        });
    }

    public function preview_violation(int $orgId, array $data): array
    {
        $token = $this->text($data, 'qr_token');
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            throw new DomainException('Invalid QR token.', 422);
        }
        $student = $this->student_for_scan($orgId, $token, false);
        return [
            'student' => array_intersect_key($student, array_flip([
                'student_number',
                'full_name',
                'section',
                'course',
                'year_level'
            ])),
            'event_type' => 'VIOLATION',
            'event_name' => 'Standalone violation',
            'fine_amount' => '0.00',
            'violation_types' => $this->violation_types($orgId),
            'default_violation_ids' => []
        ];
    }

    public function record_violation(int $orgId, array $data): void
    {
        $this->write($orgId, function () use ($orgId, $data) {
            $token = $this->text($data, 'qr_token');
            if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
                throw new DomainException('Invalid QR token.', 422);
            }
            $student = $this->student_for_scan($orgId, $token, true);
            $types = $this->selected_types($orgId, $data);
            if (!$types) {
                throw new DomainException('Select at least one violation.', 422);
            }
            $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
            $this->save_violations($orgId, (int) $student['id'], $types, $now);
        });
    }
}
