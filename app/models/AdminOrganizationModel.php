<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminOrganizationModel extends Model
{
    private function courses(): array
    {
        $catalog = $this->call->model('ProgramCatalogModel')->catalog();
        return $catalog['organization_colleges'];
    }

    public function listing(array $filters, bool $export = false): array
    {
        $search = $filters['search'] ?? '';
        $status = $filters['status'] ?? '';
        $course = $filters['course'] ?? '';
        $page = filter_var($filters['page'] ?? 1, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1
            ]
        ]);
        if (!is_string($search) || mb_strlen($search) > 100 || !in_array($status, [
            '',
            'ACTIVE',
            'INACTIVE'
        ], true) || !in_array($course, array_merge([
            '',
            'ALL'
        ], $this->courses()), true) || !$page) {
            throw new DomainException('Invalid organization filters.', 422);
        }
        // This report uses correlated EXISTS for program fallback and scope locking,
        // plus an explicit LIKE escape character. Keep user values in bound parameters.
        $where = "role='ORGANIZATION'";
        $params = [];
        if (trim($search) !== '') {
            $where .= ' AND (organization_name LIKE ? OR username LIKE ? OR email LIKE ?)';
            $term = '%' . str_replace([
                '!',
                '%',
                '_'
            ], [
                '!!',
                '!%',
                '!_'
            ], trim($search)) . '%';
            $where = str_replace('LIKE ?', 'LIKE ? ESCAPE \'!\'', $where);
            array_push($params, $term, $term, $term);
        }
        if ($status !== '') {
            $where .= ' AND status=?';
            $params[] = $status;
        }
        if ($course === 'ALL') {
            $where .= " AND organization_scope='ALL'";
        } elseif ($course !== '') {
            $where .= " AND organization_scope='COURSE' AND organization_course=?";
            $params[] = $course;
        }
        $catalog = $this->call->model('ProgramCatalogModel')->catalog();
        $program = $filters['program'] ?? '';
        if (!is_string($program) || ($program !== '' && !isset($catalog['student_programs'][$program]))) {
            throw new DomainException('Invalid program filter.', 422);
        }
        if ($program !== '') {
            $groups = [];
            foreach ($catalog['organization_course_groups'] as $key => $values) {
                if (in_array($program, $values, true)) {
                    $groups[] = $key;
                }
            }
            $legacy = $groups ? 'organization_course IN (' . implode(',', array_fill(0, count($groups), '?')) . ')' : '1=0';
            $where .= " AND (organization_scope='ALL' OR EXISTS(SELECT 1 FROM organization_programs op WHERE op.org_user_id=users.id AND op.program=?) OR (NOT EXISTS(SELECT 1 FROM organization_programs op WHERE op.org_user_id=users.id) AND {$legacy}))";
            array_push($params, $program,...$groups);
        }
        $total = (int) $this->db->raw('SELECT COUNT(*) FROM users WHERE ' . $where, $params)->fetchColumn();
        $pages = max(1, (int) ceil($total / 10));
        $page = min($page, $pages);
        $offset = ($page - 1) * 10;
        $sql = "SELECT id,organization_name,username,email,status,organization_scope,organization_course,
            (EXISTS(SELECT 1 FROM org_events e WHERE e.org_user_id=users.id) OR EXISTS(SELECT 1 FROM org_violation_types v WHERE v.org_user_id=users.id) OR EXISTS(SELECT 1 FROM payment_claims p WHERE p.org_user_id=users.id)) scope_locked
            FROM users WHERE {$where} ORDER BY organization_name,username,id" . ($export ? '' : " LIMIT 10 OFFSET {$offset}");
        $rows = $this->db->raw($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
        $catalog = $this->call->model('ProgramCatalogModel')->catalog();
        $groups = [];
        foreach ($catalog['organization_course_groups'] as $college => $programs) {
            $groups[$college] = array_values(array_intersect($programs, array_keys($catalog['student_programs'])));
        }
        foreach ($rows as & $row) {
            $selected = $this->db->table('organization_programs')
                ->select('program')
                ->where('org_user_id', '=', $row['id'])
                ->order_by('program', 'ASC')
                ->get_all(PDO::FETCH_COLUMN);
            $row['organization_programs'] = array_values(array_intersect($selected ?: ($groups[$row['organization_course']] ?? []), array_keys($catalog['student_programs'])));
            $row['student_access'] = $row['organization_scope'] === 'ALL' ? 'All courses' : implode(', ', $row['organization_programs']);
        }
        unset($row);
        $summary = $this->db->table('users')
            ->select("COUNT(*) AS total,SUM(status='ACTIVE') AS active,SUM(status='INACTIVE') AS inactive")
            ->where('role', 'ORGANIZATION')
            ->get();
        foreach ($summary as & $value) {
            $value = (int) $value;
        }
        unset($value);
        return [
            'data' => $rows,
            'summary' => $summary,
            'courses' => $this->courses(),
            'program_groups' => $groups,
            'programs' => $catalog['student_programs'],
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $pages,
                'total_records' => $total
            ],
            'columns' => [
                'organization_name' => 'Organization',
                'username' => 'Username',
                'email' => 'Email',
                'student_access' => 'Student Access'
            ]
        ];
    }

    public function save(array $data, bool $update): void
    {
        foreach ([
            'organization_name',
            'username',
            'email',
            'organization_scope',
            'organization_course',
            'status',
            'password'
        ] as $key) {
            if (isset($data[$key]) && !is_string($data[$key])) {
                throw new DomainException('Invalid organization details.', 422);
            }
        }
        $name = trim($data['organization_name'] ?? '');
        $username = trim($data['username'] ?? '');
        $email = trim($data['email'] ?? '');
        $scope = $data['organization_scope'] ?? 'COURSE';
        $course = $data['organization_course'] ?? '';
        $status = $data['status'] ?? 'ACTIVE';
        $catalog = $this->call->model('ProgramCatalogModel')->catalog();
        $custom = array_key_exists('organization_programs', $data);
        $programs = $data['organization_programs'] ?? [];
        if ($custom && (!is_array($programs) || count($programs) > count($catalog['student_programs']))) {
            throw new DomainException('Select valid programs.', 422);
        }
        foreach ($programs as $program) {
            if (!is_string($program) || !isset($catalog['student_programs'][$program])) {
                throw new DomainException('Select valid programs.', 422);
            }
        }
        $programs = array_values(array_unique($programs));
        sort($programs);
        if ($custom && $scope === 'COURSE' && !$programs) {
            throw new DomainException('Select at least one program.', 422);
        }
        $allowedGroups = $update ? array_keys($catalog['organization_course_groups']) : $this->courses();
        if ($name === '' || mb_strlen($name) > 150 || !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username) || strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($scope, [
            'ALL',
            'COURSE'
        ], true) || !in_array($status, [
            'ACTIVE',
            'INACTIVE'
        ], true) || (!$custom && $scope === 'COURSE' && !in_array($course, $allowedGroups, true))) {
            throw new DomainException('Enter valid organization details and student access.', 422);
        }
        $id = $update ? filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1
            ]
        ]) : null;
        if ($update && !$id) {
            throw new DomainException('Invalid organization ID.', 422);
        }
        $password = $data['password'] ?? '';
        if (!$update && (strlen($password) < 8 || strlen($password) > 72)) {
            throw new DomainException('Password must contain 8–72 bytes.', 422);
        }
        if ($update && $password !== '') {
            throw new DomainException('Password changes are not supported by this form.', 422);
        }
        $course = ($scope === 'ALL' || $custom) ? null : $course;
        $persistPrograms = true;
        $this->db->transaction();
        try {
            if ($update) {
                // Lock the scope while checking whether existing records prevent changes.
                $old = $this->db->raw("SELECT id, status, organization_scope, organization_course FROM users WHERE id=? AND role='ORGANIZATION' FOR UPDATE", [
                    $id
                ])->fetch(PDO::FETCH_ASSOC);
                if (!$old) {
                    throw new DomainException('Organization not found.', 404);
                }
                if (!array_key_exists('status', $data)) {
                    $status = $old['status'];
                }
                $oldPrograms = $this->db->table('organization_programs')
                    ->select('program')
                    ->where('org_user_id', '=', $id)
                    ->order_by('program', 'ASC')
                    ->get_all(PDO::FETCH_COLUMN);
                $oldHasMapping = count($oldPrograms) > 0;
                $oldPrograms = array_values(array_intersect($oldPrograms ?: ($catalog['organization_course_groups'][$old['organization_course']] ?? []), array_keys($catalog['student_programs'])));
                sort($oldPrograms);
                $scopeChanged = $custom ? ($old['organization_scope'] !== $scope || ($scope !== 'ALL' && $oldPrograms !== $programs)) : ($old['organization_scope'] !== $scope || $old['organization_course'] !== $course);
                if ($scopeChanged) {
                    $used = false;
                    foreach ([
                        'org_events',
                        'org_violation_types',
                        'payment_claims'
                    ] as $table) {
                        if ($this->db->table($table)
                            ->where('org_user_id', $id)
                            ->limit(1)
                            ->get()) {
                            $used = true;
                            break;
                        }
                    }
                    if ($used) {
                        throw new DomainException('Student access cannot change after an organization has records. Its history must remain linked to the original scope.', 409);
                    }
                }
                if ($custom && !$scopeChanged) {
                    $course = $old['organization_course'];
                    if (!$oldHasMapping) {
                        $persistPrograms = false;
                    }
                }
            }
            if ($this->db->table('users')
                ->select('id')
                ->grouped(function ($query) use ($username, $email) {
                    $query->where('username', $username)
                        ->or_where('email', $email);
                })
                ->where('id', '<>', $id ?? 0)
                ->limit(1)
                ->get()) {
                throw new DomainException('Username or email is already used.', 409);
            }
            if ($update) {
                $this->db->table('users')
                    ->where('id', '=', $id)
                    ->where('role', '=', 'ORGANIZATION')
                    ->update([
                        'organization_name' => $name,
                        'username' => $username,
                        'email' => $email,
                        'organization_scope' => $scope,
                        'organization_course' => $course,
                        'status' => $status
                    ]);
                if ($status === 'INACTIVE') {
                    $this->db->table('refresh_tokens')
                        ->where('user_id', '=', $id)
                        ->delete();
                }
            } else {
                $this->db->table('users')
                    ->insert([
                        'organization_name' => $name,
                        'username' => $username,
                        'email' => $email,
                        'organization_scope' => $scope,
                        'organization_course' => $course,
                        'status' => $status,
                        'role' => 'ORGANIZATION',
                        'password_hash' => password_hash($password, PASSWORD_BCRYPT)
                    ]);
                $id = (int) $this->db->last_id();
            }
            if ($custom && $persistPrograms) {
                $this->db->table('organization_programs')
                    ->where('org_user_id', '=', $id)
                    ->delete();
                if ($scope === 'COURSE') {
                    foreach ($programs as $program) {
                        $this->db->table('organization_programs')
                            ->insert([
                                'org_user_id' => $id,
                                'program' => $program,
                            ]);
                    }
                }
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
                throw new DomainException('Username or email is already used.', 409);
            }
            throw $e;
        }
    }
}
