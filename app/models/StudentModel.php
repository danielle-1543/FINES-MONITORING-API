<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class StudentModel extends Model
{
    protected $table = 'students';

    public function programs(): array
    {
        require_once __DIR__ . '/ProgramCatalogModel.php';
        return (new ProgramCatalogModel())->catalog()['student_programs'];
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
        $columns = 'id,user_id,student_number,full_name,section,course,year_level,account_status,activation_status,qr_token_status,qr_token_generated_at,activation_code_expires_at,registered_at,activated_at,updated_at';
        if ($forExport) {
            $columns .= ',qr_token,activation_code';
        }
        $query = $this->filtered($filters)
            ->select($columns);
        foreach (explode(',', $sortOptions[$filters['sort'] ?? 'recent'] ?? $sortOptions['recent']) as $order) {
            [$field, $direction] = explode(' ', trim($order));
            $query->order_by($field, $direction);
        }
        return [
            'students' => $query->limit($perPage, ($page - 1) * $perPage)
                ->get_all(),
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

    public function register_student_record(array $d): bool
    {
        $fields = [
            'user_id',
            'student_number',
            'full_name',
            'section',
            'course',
            'year_level',
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

    public function update_student_details(int $id, array $d): bool
    {
        return $this->db->table('students')
            ->where('id', $id)
            ->update([
                'student_number' => $d['student_number'],
                'full_name' => $d['full_name'],
                'section' => $d['section'],
                'course' => $d['course'],
                'year_level' => $d['year_level'],
            ]) !== false;
    }

    public function delete_pending_student(int $id): bool
    {
        return $this->db->table('students')
            ->where('id', $id)
            ->where('activation_status', 'PENDING')
            ->where_null('user_id')
            ->delete() !== false;
    }

    public function set_account_status(int $id, string $status): bool
    {
        return $this->db->table('students')
            ->where('id', $id)
            ->update([
                'account_status' => $status
            ]) !== false;
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

    public function set_qr_status(int $id, string $status, ?string $token = null): bool
    {
        $values = [
            'qr_token_status' => 'REVOKED'
        ];
        if ($status !== 'REVOKED') {
            $values = [
                'qr_token' => $token,
                'qr_token_status' => 'ACTIVE',
                'qr_token_generated_at' => date('Y-m-d H:i:s'),
            ];
        }
        return $this->db->table('students')
            ->where('id', $id)
            ->update($values) !== false;
    }
}
