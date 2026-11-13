<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class StudentModel extends Model
{
    protected $table = 'students';
    protected $fillable = ['student_number', 'full_name', 'section', 'course', 'year_level'];

    private function update_values(array $input, int $count): array
    {
        $allowed = [
            'PROMOTION' => ['year_level', 'section', 'current_academic_period_id'],
            'RETAINED' => ['year_level', 'section', 'current_academic_period_id'],
            'SECTION_CHANGE' => ['section'],
            'SEMESTER_CHANGE' => ['current_academic_period_id', 'year_level', 'section'],
            'SHIFT_COURSE' => ['course', 'year_level', 'section', 'current_academic_period_id'],
            'TRANSFERRED_OUT' => [],
            'DROPPED_OUT' => [],
            'OTHER_CORRECTION' => ['student_number', 'full_name', 'year_level', 'section', 'current_academic_period_id'],
            'DEACTIVATE' => [],
            'REACTIVATE' => [],
        ];
        $reason = $input['reason'] ?? null;
        $note = $input['note'] ?? '';
        $changes = $input['changes'] ?? [];
        if (!is_string($reason) || !isset($allowed[$reason]) || !is_string($note)
            || mb_strlen(trim($note)) > 1000 || !is_array($changes)) {
            throw new DomainException('Choose a valid reason. Optional notes can contain up to 1,000 characters.', 422);
        }
        $values = [];
        foreach ($changes as $field => $value) {
            if (!in_array($field, $allowed[$reason], true) || !is_string($value)) {
                throw new DomainException('This reason does not allow the selected fields.', 422);
            }
            if (trim($value) !== '') {
                $values[$field] = trim($value);
            }
        }
        if ($allowed[$reason] && !$values) {
            throw new DomainException('Choose at least one value to update.', 422);
        }
        if ($count !== 1 && (isset($values['student_number']) || isset($values['full_name']))) {
            throw new DomainException('Names and student numbers can only be corrected one student at a time.', 422);
        }
        if (isset($values['year_level']) && !in_array($values['year_level'], ['1st Year', '2nd Year', '3rd Year', '4th Year'], true)) {
            throw new DomainException('Choose a valid year level.', 422);
        }
        if (isset($values['section'])) {
            $values['section'] = strtoupper($values['section']);
            if (!preg_match('/^[A-Z]+[1-9][0-9]*$/', $values['section']) || mb_strlen($values['section']) > 50) {
                throw new DomainException('Use a section identifier such as F1 or F2, without the year level.', 422);
            }
        }
        if (isset($values['full_name']) && mb_strlen($values['full_name']) > 150) {
            throw new DomainException('Names must be 1–150 characters.', 422);
        }
        if (isset($values['student_number'])) {
            $values['student_number'] = strtoupper($values['student_number']);
            if (!preg_match('/^MCC-[0-9]{4}-[0-9]{5}$/', $values['student_number'])) {
                throw new DomainException('Enter a valid student number.', 422);
            }
        }
        if ($reason === 'SHIFT_COURSE') {
            $values['course'] = strtoupper($values['course'] ?? '');
            if (!in_array($values['course'], array_merge(array_keys($this->programs()), ['CCS', 'CAS', 'CRIM']), true)) {
                throw new DomainException('Choose a valid destination course.', 422);
            }
        }
        if (isset($values['current_academic_period_id'])) {
            if (!ctype_digit($values['current_academic_period_id'])) throw new DomainException('Choose a valid semester.', 422);
            $this->call->model('AcademicPeriodModel')->period((int) $values['current_academic_period_id'], true);
        }
        return [$reason, trim($note), $values];
    }

    public function update_students(array $input, int $adminId): array
    {
        if (!isset($input['preview']) || !is_bool($input['preview'])) {
            throw new DomainException('Preview the proposed changes before saving.', 422);
        }
        $preview = $input['preview'];
        $ids = $input['student_ids'] ?? [];
        if ($preview && ($input['all_matching'] ?? false) === true) {
            if (!is_array($input['filters'] ?? null)) {
                throw new DomainException('Choose valid student filters.', 422);
            }
            $ids = $this->matching_student_ids($input['filters']);
        }
        if (!is_array($ids) || !$ids || count($ids) > 2000) {
            throw new DomainException('Select 1–2,000 students. Narrow the filters for larger batches.', 422);
        }
        $validIds = [];
        foreach ($ids as $id) {
            $valid = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($valid === false) {
                throw new DomainException('Invalid student selection.', 422);
            }
            $validIds[] = (int) $valid;
        }
        $ids = array_values(array_unique($validIds));
        sort($ids, SORT_NUMERIC);
        [$reason, $note, $values] = $this->update_values($input, count($ids));
        $this->db->transaction();
        try {
            // Lock the exact reviewed students in a stable order before validating or writing.
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $students = $this->db->raw('SELECT id,user_id,student_number,full_name,course,year_level,section,current_academic_period_id,account_status,departure_reason,
                (SELECT COALESCE(MAX(h.id),0) FROM student_change_history h WHERE h.student_id=students.id) AS edit_revision
                FROM students WHERE id IN (' . $placeholders . ') ORDER BY id ASC FOR UPDATE', $ids)->fetchAll(PDO::FETCH_ASSOC);
            if (count($students) !== count($ids)) {
                throw new DomainException('A selected student no longer exists. Select the students again.', 409);
            }
            if (isset($input['filters'])) {
                if (!is_array($input['filters'])) {
                    throw new DomainException('Choose valid student filters.', 422);
                }
                $matching = $this->matching_student_ids($input['filters'], false, $ids);
                if (array_diff($ids, $matching)) {
                    throw new DomainException('Some selected students no longer match the filters. Clear the selection and select the matching records again.', 409);
                }
            }
            $review = [];
            $periodLabels = array_column($this->call->model('AcademicPeriodModel')->periods(), 'label', 'id');
            foreach ($students as $student) {
                $student['academic_period_label'] = $periodLabels[$student['current_academic_period_id'] ?? 0] ?? 'Not assigned';
                if ($reason === 'DEACTIVATE' && !in_array($student['departure_reason'], ['TRANSFERRED_OUT', 'DROPPED_OUT'], true)) {
                    throw new DomainException('Deactivation is available only after recording transfer out or dropout for every selected student.', 409);
                }
                if (isset($values['student_number']) && $this->student_number_exists($values['student_number'], (int) $student['id'])) {
                    throw new DomainException('Student number already belongs to another student.', 409);
                }
                $after = array_replace($student, $values);
                $after['academic_period_label'] = $periodLabels[$after['current_academic_period_id'] ?? 0] ?? 'Not assigned';
                if (in_array($reason, ['TRANSFERRED_OUT', 'DROPPED_OUT'], true)) {
                    $after['departure_reason'] = $reason;
                } elseif (in_array($reason, ['PROMOTION', 'RETAINED', 'SHIFT_COURSE', 'REACTIVATE'], true)) {
                    $after['departure_reason'] = null;
                }
                if ($reason === 'DEACTIVATE' || $reason === 'REACTIVATE') {
                    $after['account_status'] = $reason === 'DEACTIVATE' ? 'INACTIVE' : 'ACTIVE';
                }
                $review[] = ['id' => (int) $student['id'], 'before' => $student, 'after' => $after];
            }
            $fingerprint = hash('sha256', json_encode([$reason, $note, $values, $review], JSON_THROW_ON_ERROR));
            if ($preview) {
                $this->db->commit();
                return ['students' => $review, 'fingerprint' => $fingerprint, 'count' => count($review)];
            }
            if (!is_string($input['fingerprint'] ?? null) || !hash_equals($fingerprint, $input['fingerprint'])) {
                throw new DomainException('The selection or student details changed. Review a fresh preview before saving.', 409);
            }
            $batch = bin2hex(random_bytes(16));
            $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
            foreach ($review as $change) {
                $updates = array_intersect_key($change['after'], array_flip(['student_number', 'full_name', 'course', 'year_level', 'section', 'current_academic_period_id', 'account_status', 'departure_reason']));
                if ($this->db->table('students')->where('id', $change['id'])->update($updates) === false) {
                    throw new RuntimeException('Student update failed.');
                }
                if (in_array($reason, ['DEACTIVATE', 'REACTIVATE'], true) && !empty($change['before']['user_id'])) {
                    $userId = (int) $change['before']['user_id'];
                    // Incrementing the version invalidates previously issued access tokens.
                    $this->db->raw('UPDATE users SET status=?,auth_version=auth_version+1 WHERE id=? AND role=?', [
                        $change['after']['account_status'], $userId, 'STUDENT',
                    ]);
                    $this->db->table('refresh_tokens')->where('user_id', $userId)->delete();
                }
                if ($this->db->table('student_change_history')->insert([
                    'student_id' => $change['id'], 'changed_by' => $adminId, 'batch_id' => $batch,
                    'reason' => $reason, 'note' => $note,
                    'before_values' => json_encode($change['before'], JSON_THROW_ON_ERROR),
                    'after_values' => json_encode($change['after'], JSON_THROW_ON_ERROR), 'changed_at' => $now,
                ]) === false) {
                    throw new RuntimeException('Student history could not be saved.');
                }
            }
            $this->db->commit();
            return ['count' => count($review), 'batch_id' => $batch];
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    public function matching_student_ids(array $filters, bool $requireFilter = true, ?array $selectedIds = null): array
    {
        $keys = ['search', 'account_status', 'activation_status', 'course', 'year_level', 'section', 'academic_period_id'];
        $active = [];
        foreach ($keys as $key) {
            $value = $filters[$key] ?? '';
            if (!is_string($value) || mb_strlen($value) > 150) {
                throw new DomainException('Choose valid student filters.', 422);
            }
            if (trim($value) !== '') {
                $active[$key] = trim($value);
            }
        }
        foreach (['account_status' => ['ACTIVE', 'INACTIVE'], 'activation_status' => ['PENDING', 'ACTIVATED']] as $key => $allowed) {
            if (isset($active[$key]) && !in_array($active[$key], $allowed, true)) {
                throw new DomainException('Choose valid student filters.', 422);
            }
        }
        if ($requireFilter && !$active) {
            throw new DomainException('Apply a student filter before selecting all matching records.', 422);
        }
        $query = $this->filtered($active)->select('id');
        if ($selectedIds !== null) {
            $query->in('id', $selectedIds);
        }
        $rows = $query->order_by('id', 'ASC')->limit(2001)->get_all();
        return array_map('intval', array_column($rows, 'id'));
    }

    public function change_history(int $studentId): array
    {
        $rows = $this->db->table('student_change_history h')
            ->select('h.id,h.reason,h.note,h.before_values,h.after_values,h.changed_at,u.username AS changed_by')
            ->left_join('users u', 'u.id=h.changed_by')
            ->where('h.student_id', $studentId)->order_by('h.id', 'DESC')->limit(100)->get_all();
        foreach ($rows as &$row) {
            $row['before_values'] = json_decode($row['before_values'], true);
            $row['after_values'] = json_decode($row['after_values'], true);
        }
        return $rows;
    }

    public function programs(): array
    {
        return $this->call->model('ProgramCatalogModel')->catalog()['student_programs'];
    }

    public function get_summary(): array
    {
        $result = $this->db->table('students')
            ->select(implode(', ', [
                'COUNT(*) AS total_students',
                "SUM(account_status = 'ACTIVE') AS active_students",
                "SUM(account_status = 'INACTIVE') AS inactive_students",
                "SUM(activation_status = 'PENDING') AS pending_students",
                "SUM(activation_status = 'ACTIVATED') AS activated_students",
            ]))
            ->get();
        return array_map('intval', $result);
    }

    private function filtered(array $filters)
    {
        $query = $this->db->table('students');
        if (!empty($filters['academic_period_id'])) $query->where('current_academic_period_id', (int) $filters['academic_period_id']);
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $query->grouped(function ($query) use ($search) {
                $query->like('student_number', '%' . $search . '%')
                    ->or_like('full_name', '%' . $search . '%')
                    ->or_like('section', '%' . $search . '%')
                    ->or_like('course', '%' . $search . '%')
                    ->or_like('year_level', '%' . $search . '%');
            });
        }
        foreach ([
            'account_status' => [
                'ACTIVE',
                'INACTIVE'
            ],
            'activation_status' => [
                'PENDING',
                'ACTIVATED'
            ],
            'departure_reason' => [
                'TRANSFERRED_OUT',
                'DROPPED_OUT'
            ],
            'qr_status' => [
                'ACTIVE',
                'REVOKED'
            ]
        ] as $key => $allowed) {
            $value = strtoupper(trim($filters[$key] ?? ''));
            if (in_array($value, $allowed, true)) {
                $query->where($key === 'qr_status' ? 'qr_token_status' : $key, $value);
            }
        }
        foreach ([
            'course',
            'year_level',
            'section'
        ] as $field) {
            $value = trim($filters[$field] ?? '');
            if ($value !== '') {
                $query->where($field, $value);
            }
        }
        return $query;
    }

    public function get_students($filters = [], bool $forExport = false): array
    {
        $perPage = $forExport ? 2001 : max(1, min(100, (int) ($filters['per_page'] ?? 10)));
        $total = (int) $this->filtered($filters)
            ->count();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = $forExport ? 1 : min($pages, max(1, (int) ($filters['page'] ?? 1)));
        $sortOptions = [
            'name_asc' => 'full_name ASC',
            'name_desc' => 'full_name DESC',
            'number_asc' => 'student_number ASC',
            'number_desc' => 'student_number DESC',
            'course_asc' => 'course ASC',
            'course_desc' => 'course DESC',
            'year_asc' => 'year_level ASC',
            'year_desc' => 'year_level DESC',
            'section_asc' => 'section ASC',
            'recent' => 'registered_at DESC, id DESC',
            'oldest' => 'registered_at ASC, id ASC',
            'activated_recent' => 'activated_at DESC, id DESC',
        ];
        $columns = 'id,user_id,student_number,full_name,section,course,year_level,current_academic_period_id,account_status,departure_reason,activation_status,qr_token_status,qr_token_generated_at,activation_code_expires_at,registered_at,activated_at,updated_at';
        if ($forExport) {
            $columns .= ',qr_token,activation_code';
        }
        $query = $this->filtered($filters)
            ->select($columns);
        foreach (explode(',', $sortOptions[$filters['sort'] ?? 'recent'] ?? $sortOptions['recent']) as $order) {
            [$field, $direction] = explode(' ', trim($order));
            $query->order_by($field, $direction);
        }
        $students = $query->limit($perPage, ($page - 1) * $perPage)->get_all();
        $periodLabels = array_column($this->db->table('academic_periods')->select('id,label')->get_all(), 'label', 'id');
        foreach ($students as &$student) {
            $student['academic_period_label'] = $periodLabels[$student['current_academic_period_id'] ?? 0] ?? 'Not assigned';
        }
        unset($student);
        return [
            'students' => $students,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_records' => $total,
                'total_pages' => $pages,
            ],
        ];
    }

    public function get_filter_options(): array
    {
        $options = [];
        foreach ([
            'courses' => 'course',
            'year_levels' => 'year_level',
            'sections' => 'section'
        ] as $key => $column) {
            $options[$key] = $this->db->table('students')
                ->select_distinct($column)
                ->where($column, '<>', '')
                ->order_by($column, 'ASC')
                ->get_all(PDO::FETCH_COLUMN);
        }
        $options['courses'] = array_keys($this->programs());
        return $options;
    }

    public function student_number_exists(string $number, ?int $excludeId = null): bool
    {
        $query = $this->db->table('students')
            ->select('id')
            ->where('student_number', $number);
        if ($excludeId !== null) {
            $query->where('id', '<>', $excludeId);
        }
        return (bool) $query->limit(1)
            ->get();
    }

    public function student_details_exist(array $d, ?int $excludeId = null): bool
    {
        // Nested LOWER(TRIM(...)) expressions are not accepted by the installed builder.
        // Keep bound values so duplicate checks retain their normalization rules.
        $sql = 'SELECT id FROM students
            WHERE LOWER(TRIM(full_name)) = LOWER(TRIM(:name))
            AND LOWER(TRIM(section)) = LOWER(TRIM(:section))
            AND course = :course
            AND year_level = :year';
        $params = [
            'name' => $d['full_name'],
            'section' => $d['section'],
            'course' => $d['course'],
            'year' => $d['year_level']
        ];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }
        return (bool) $this->db->raw($sql . ' LIMIT 1', $params)->fetch(PDO::FETCH_ASSOC);
    }

    public function find_by_id(int $id): ?array
    {
        return $this->db->table('students')
            ->where('id', $id)
            ->limit(1)
            ->get() ?: null;
    }

    public function profile_details(int $id): ?array
    {
        $student = $this->find_by_id($id);
        if (!$student) {
            return null;
        }
        $details = array_intersect_key($student, array_flip([
            'id', 'user_id', 'student_number', 'full_name', 'course', 'year_level', 'section',
            'account_status', 'activation_status', 'departure_reason', 'registered_at', 'activated_at',
            'updated_at', 'qr_token_status', 'qr_token_generated_at', 'activation_code_expires_at',
        ]));
        $account = empty($student['user_id']) ? [] : $this->db->table('users')
            ->select('username,email')->where('id', (int) $student['user_id'])->get();
        $details['username'] = $account['username'] ?? null;
        $details['email'] = $account['email'] ?? null;
        $details['current_academic_period_id'] = $student['current_academic_period_id'] ?? null;
        $period = empty($details['current_academic_period_id']) ? null : $this->db->table('academic_periods')->select('label')->where('id', $details['current_academic_period_id'])->get();
        $details['academic_period_label'] = $period['label'] ?? 'Not assigned';
        $credentials = $this->db->table('student_qr_credentials')
            ->select('generated_at, status')->where('student_id', $id)
            ->order_by('generated_at', 'ASC')->get_all();
        $details['qr_first_generated_at'] = $credentials[0]['generated_at'] ?? $student['qr_token_generated_at'];
        $details['qr_last_regenerated_at'] = count($credentials) > 1 ? $student['qr_token_generated_at'] : null;
        return $details;
    }

    public function register_student_record(array $d): bool
    {
        $fields = [
            'user_id',
            'student_number',
            'full_name',
            'section',
            'course',
            'year_level',
            'current_academic_period_id',
            'account_status',
            'activation_status',
            'qr_token',
            'qr_token_status',
            'qr_token_generated_at',
            'activation_code_hash',
            'activation_code',
            'activation_code_expires_at'
        ];
        $record = [];
        foreach ($fields as $field) {
            $record[$field] = $d[$field] ?? null;
        }
        return $this->db->table('students')
            ->insert($record) !== false;
    }

    public function find_by_number(string $number): ?array
    {
        return $this->db->table('students')->where('student_number', '=', $number)->get() ?: null;
    }

    public function register_for_period(array $record, int $periodId): bool
    {
        $this->call->model('AcademicPeriodModel')->period($periodId, true);
        $record['current_academic_period_id'] = $periodId;
        return $this->register_student_record($record);
    }

    public function delete_pending_student(int $id): bool
    {
        if ($this->db->table('student_change_history')->where('student_id', $id)->count() > 0) {
            throw new DomainException('Students with edit history must be deactivated instead of deleted.', 409);
        }
        return $this->db->table('students')
            ->where('id', $id)
            ->where('activation_status', 'PENDING')
            ->where_null('user_id')
            ->delete() !== false;
    }

    public function replace_activation_code(int $id, string $hash, string $code, string $expires): bool
    {
        return $this->db->table('students')
            ->where('id', $id)
            ->where('activation_status', 'PENDING')
            ->where_null('user_id')
            ->update([
                'activation_code_hash' => $hash,
                'activation_code' => $code,
                'activation_code_expires_at' => $expires,
            ]) !== false;
    }

}
