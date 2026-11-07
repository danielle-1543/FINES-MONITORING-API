<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * Model: AdminDashboardModel
 *
 * Automatically generated via CLI.
 */
/**
 * AdminDashboardModel
 *
 * Handles database queries needed by the admin dashboard.
 * Uses the existing students table.
 */

class AdminDashboardModel extends Model
{
    public function overview(array $filters): array
    {
        $course = $filters['course'] ?? '';
        $page = filter_var($filters['page'] ?? 1, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1
            ]
        ]);
        if (!is_string($course) || mb_strlen($course) > 100 || !$page) {
            throw new DomainException('Invalid dashboard filters.', 422);
        }
        $courses = $this->db->table('students')
            ->select_distinct('course')
            ->where_not_null('course')
            ->where('course', '<>', '')
            ->order_by('course', 'ASC')
            ->get_all(PDO::FETCH_COLUMN);
        if ($course !== '' && !in_array($course, $courses, true)) {
            throw new DomainException('Unknown course.', 422);
        }
        $students = $this->db->table('students');
        if ($course !== '') {
            $students->where('course', $course);
        }
        $count = (int) $students->count();
        $orgs = (int) $this->db->table('users')
            ->where('role', '=', 'ORGANIZATION')
            ->count();
        $total = (int) $this->db->table('activity_logs')
            ->count();
        $pages = max(1, (int) ceil($total / 25));
        $page = min($page, $pages);
        $offset = ($page - 1) * 25;
        $logs = $this->db->table('activity_logs')
            ->select('id,actor_name,actor_role,action,created_at')
            ->order_by('created_at', 'DESC')
            ->order_by('id', 'DESC')
            ->limit(25, $offset)
            ->get_all();
        return [
            'student_count' => $count,
            'organization_count' => $orgs,
            'courses' => $courses,
            'activity' => $logs,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $pages,
                'total_records' => $total
            ]
        ];
    }

    protected $table = 'students';

    public function __construct()
    {
        parent::__construct();
    }
    /**
     * Count student accounts by activation status.
     *
     * ACTIVATED = Students who completed account activation.
     * PENDING   = Students who have not completed activation.
     *
     * This does not count ADMIN or ORGANIZATION accounts.
     */

    public function get_activation_counts()
    {
        $activated = $this->db->table($this->table)
            ->where('activation_status', 'ACTIVATED')
            ->count();
        $pending = $this->db->table($this->table)
            ->where('activation_status', 'PENDING')
            ->count();
        // Return counts in a predictable structure for the API.
        return [
            'activated_accounts' => (int) $activated,
            'pending_accounts' => (int) $pending,
        ];
    }
}
