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
    public function activity_analytics(array $filters): array
    {
        $refresh = $filters['refresh'] ?? '0';
        if (!in_array($refresh, ['0', '1'], true)) {
            throw new DomainException('Choose a valid analytics refresh option.', 422);
        }
        $days = filter_var($filters['days'] ?? 7, FILTER_VALIDATE_INT);
        $environment = $filters['environment'] ?? 'all';
        if (!in_array($days, [7, 30, 90], true)
            || !is_string($environment)
            || !in_array($environment, ['all', 'development', 'production'], true)) {
            throw new DomainException('Choose a valid analytics date range and environment.', 422);
        }
        $host = rtrim(getenv('POSTHOG_API_HOST') ?: '', '/');
        $project = trim(getenv('POSTHOG_PROJECT_ID') ?: '');
        $key = trim(getenv('POSTHOG_PERSONAL_API_KEY') ?: '');
        if (!in_array($host, ['https://us.posthog.com', 'https://eu.posthog.com'], true)
            || !ctype_digit($project) || $key === '') {
            throw new DomainException('PostHog reporting is not configured on the server.', 503);
        }
        if (!function_exists('curl_init')) {
            throw new DomainException('The server needs PHP cURL to load analytics.', 503);
        }
        $zone = new DateTimeZone('Asia/Manila');
        $today = new DateTimeImmutable('today', $zone);
        $from = $today->modify('-' . ($days - 1) . ' days');
        $until = $today->modify('+1 day');
        $utc = new DateTimeZone('UTC');
        $fromUtc = $from->setTimezone($utc)->getTimestamp();
        $untilUtc = $until->setTimezone($utc)->getTimestamp();
        $cacheKey = 'posthog-admin-complete-features-' . hash('sha256', json_encode([
            $host, $project, hash('sha256', $key), $days, $environment, $fromUtc,
        ]));
        $this->call->library('cache');
        $cached = $this->cache->get($cacheKey);
        if ($refresh !== '1' && is_array($cached)) {
            return $cached;
        }
        // Only server-generated timestamps and enumerated filters enter this query.
        // Never accept arbitrary SQL or event properties from the browser.
        $environmentFilter = $environment === 'all' ? '' : " AND properties.environment = '{$environment}'";
        $query = "SELECT event, toString(toDate(toTimeZone(timestamp, 'Asia/Manila'))) AS day,
            if(event = 'feature_used', coalesce(toString(properties.feature), 'unknown'), '') AS feature,
            upper(coalesce(toString(properties.role), 'UNKNOWN')) AS role,
            count() AS total
            FROM events
            WHERE timestamp >= toDateTime({$fromUtc})
                AND timestamp < toDateTime({$untilUtc})
                AND event IN ('login_success','login_failed','logout','account_activated','feature_used')
                {$environmentFilter}
            GROUP BY event, day, feature, role
            ORDER BY day ASC
            LIMIT 10000";
        $handle = curl_init($host . '/api/projects/' . $project . '/query/');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
            CURLOPT_POSTFIELDS => json_encode([
                'query' => ['kind' => 'HogQLQuery', 'query' => $query],
                'refresh' => 'force_blocking',
            ], JSON_THROW_ON_ERROR),
        ]);
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($handle, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
        $body = curl_exec($handle);
        $transportError = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if ($transportError !== 0) {
            throw new DomainException('Unable to connect securely to PostHog. Try again shortly.', 502);
        }
        if ($status === 401 || $status === 403) {
            throw new DomainException('PostHog reporting key was rejected. Check its project access and Query Read permission.', 503);
        }
        if ($status === 429) {
            throw new DomainException('PostHog reporting is temporarily rate limited. Try again later.', 503);
        }
        $response = is_string($body) ? json_decode($body, true) : null;
        if ($status !== 200 || !is_array($response) || !is_array($response['results'] ?? null)) {
            error_log('PostHog aggregate query failed; HTTP ' . $status);
            throw new DomainException('Unable to load the PostHog activity report.', 502);
        }
        if (!empty($response['hasMore']) || count($response['results']) >= 10000) {
            throw new DomainException('The activity report is too large. Choose a shorter date range.', 422);
        }
        $counts = array_fill_keys(['login_success', 'login_failed', 'logout', 'account_activated', 'feature_used'], 0);
        $daily = [];
        for ($date = $from; $date < $until; $date = $date->modify('+1 day')) {
            $daily[$date->format('Y-m-d')] = 0;
        }
        $features = [];
        foreach ($response['results'] as $row) {
            if (!is_array($row) || count($row) !== 5 || !array_key_exists($row[0], $counts)) {
                throw new DomainException('PostHog returned an unexpected report format.', 502);
            }
            [$event, $date, $feature, $role, $count] = $row;
            $count = max(0, (int) $count);
            $counts[$event] += $count;
            if (array_key_exists($date, $daily)) {
                $daily[$date] += $count;
            }
            if ($event === 'feature_used') {
                $feature = is_string($feature) && preg_match('/^[a-z_]{1,50}$/', $feature) ? $feature : 'unknown';
                $role = in_array($role, ['ADMIN', 'STUDENT', 'ORGANIZATION'], true) ? $role : 'UNKNOWN';
                $id = $role . ':' . $feature;
                if (!isset($features[$id])) {
                    $features[$id] = ['feature' => $feature, 'role' => $role, 'count' => 0];
                }
                $features[$id]['count'] += $count;
            }
        }
        usort($features, static fn ($a, $b) => $b['count'] <=> $a['count']);
        $report = [
            'counts' => $counts,
            'total_events' => array_sum($counts),
            'daily' => array_map(static fn ($date, $count) => ['date' => $date, 'count' => $count], array_keys($daily), array_values($daily)),
            'features' => $features,
            'days' => $days,
            'environment' => $environment,
            'from_date' => $from->format('Y-m-d'),
            'to_date' => $today->format('Y-m-d'),
            'timezone' => 'Asia/Manila',
            'updated_at' => (new DateTimeImmutable('now', $zone))->format(DATE_ATOM),
        ];
        $this->cache->write($report, $cacheKey, 60);
        return $report;
    }

    public function overview(array $filters): array
    {
        $page = filter_var($filters['page'] ?? 1, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1
            ]
        ]);
        if (!$page) {
            throw new DomainException('Invalid dashboard filters.', 422);
        }
        // One grouped query keeps course totals and account totals consistent.
        // No joins: each student is counted exactly once.
        $groups = $this->db->table('students')
            ->select('course, account_status, activation_status')
            ->select_count('id', 'student_count')
            ->group_by(['course', 'account_status', 'activation_status'])
            ->get_all();
        $count = 0;
        $distribution = [];
        $statistics = ['active' => 0, 'inactive' => 0, 'pending' => 0, 'activated' => 0];
        foreach ($groups as $group) {
            $amount = (int) $group['student_count'];
            $label = strtoupper(trim((string) $group['course']));
            $label = ['BAEL' => 'AB ENGLISH', 'BAPSYCH' => 'ABPSYCH'][$label] ?? $label;
            $label = $label !== '' ? $label : 'Unspecified';
            $distribution[$label] = ($distribution[$label] ?? 0) + $amount;
            $count += $amount;
            foreach (['account_status' => ['ACTIVE' => 'active', 'INACTIVE' => 'inactive'],
                'activation_status' => ['PENDING' => 'pending', 'ACTIVATED' => 'activated']] as $field => $labels) {
                $key = $labels[$group[$field]] ?? null;
                if ($key !== null) $statistics[$key] += $amount;
            }
        }
        ksort($distribution);
        $courses = array_keys($distribution);
        $courseDistribution = [];
        foreach ($distribution as $label => $amount) {
            $courseDistribution[] = ['label' => $label, 'count' => $amount];
        }
        $orgs = (int) $this->db->table('users')
            ->where('role', '=', 'ORGANIZATION')
            ->count();
        $total = (int) $this->db->table('activity_logs')
            ->count();
        $perPage = 10;
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $logs = $this->db->table('activity_logs')
            ->select('id,actor_name,actor_role,action,created_at')
            ->order_by('created_at', 'DESC')
            ->order_by('id', 'DESC')
            ->limit($perPage, $offset)
            ->get_all();
        return [
            'student_count' => $count,
            'student_statistics' => $statistics,
            'course_distribution' => $courseDistribution,
            'organization_count' => $orgs,
            'courses' => $courses,
            'activity' => $logs,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $pages,
                'total_records' => $total,
                'per_page' => $perPage
            ]
        ];
    }

    protected $table = 'students';
    protected $guarded = ['id'];

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
