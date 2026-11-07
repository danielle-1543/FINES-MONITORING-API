<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class OrganizationDashboardModel extends Model
{
    public function overview(int $id): array
    {
        require_once __DIR__ . '/OrgEventModel.php';
        require_once __DIR__ . '/PaymentModel.php';
        require_once __DIR__ . '/AttendanceModel.php';
        $orgs = new OrgEventModel();
        $org = $orgs->organization($id);
        (new AttendanceModel())->finalize($id);
        $params = [];
        $scope = '1=1';
        if ($org['allowed_courses'] !== null) {
            $scope = 'UPPER(TRIM(s.course)) IN (' . implode(',', array_fill(0, count($org['allowed_courses']), '?')) . ')';
            $params = $org['allowed_courses'];
        }
        $students = (int) $this->db->raw('SELECT COUNT(*) FROM students s WHERE ' . $scope, $params)->fetchColumn();
        $attendance = $this->db->raw("SELECT COALESCE(SUM(r.attendance_status='PRESENT'),0) present,COALESCE(SUM(r.attendance_status='ABSENT'),0) absent
            FROM org_scan_records r JOIN org_events e ON e.id=r.event_id JOIN students s ON s.id=r.student_id
            WHERE e.org_user_id=? AND r.record_type='ATTENDANCE' AND {$scope}", array_merge([
            $id
        ], $params))->fetch(PDO::FETCH_ASSOC);
        $recentAttendance = $this->db->raw("SELECT r.id,s.full_name,s.student_number,e.name event_name,r.attendance_status,r.recorded_at
            FROM org_scan_records r JOIN org_events e ON e.id=r.event_id JOIN students s ON s.id=r.student_id
            WHERE e.org_user_id=? AND r.record_type='ATTENDANCE' AND {$scope} ORDER BY r.recorded_at DESC,r.id DESC LIMIT 5", array_merge([
            $id
        ], $params))->fetchAll(PDO::FETCH_ASSOC);
        $payments = new PaymentModel();
        $ledger = $payments->organization_fines($id, [], true);
        $claims = $payments->organization_claims($id);
        $violations = [];
        foreach ($ledger['data'] as $student) {
            foreach ($student['fines'] as $fine) {
                if ($fine['record_type'] === 'VIOLATION') {
                    $violations[] = $fine + ['full_name' => $student['full_name'], 'student_number' => $student['student_number']];
                }
            }
        }
        usort($violations, fn ($a, $b) => strcmp($b['recorded_at'], $a['recorded_at']) ?: strcmp($b['fine_key'], $a['fine_key']));
        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
        $events = $this->db->table('org_events')
            ->select('id,name,scan_starts_at,scan_ends_at')
            ->where('org_user_id', '=', $id)
            ->where('event_type', '=', 'ATTENDANCE')
            ->where('scan_ends_at', '>=', $now)
            ->order_by('scan_starts_at', 'ASC')
            ->order_by('id', 'ASC')
            ->get_all(PDO::FETCH_ASSOC);
        $open = 0;
        foreach ($events as & $event) {
            $event['event_status'] = $event['scan_starts_at'] <= $now ? 'ONGOING' : 'UPCOMING';
            if ($event['event_status'] === 'ONGOING') {
                $open++;
            }
        }
        unset($event);
        $pending = count(array_filter($claims, fn ($claim) => $claim['status'] === 'PENDING'));
        return [
            'summary' => [
                'students' => $students,
                'present' => (int) $attendance['present'],
                'absent' => (int) $attendance['absent'],
                'violations' => count($violations),
                'open_events' => $open,
                'pending_claims' => $pending
            ] + $ledger['summary'],
            'recent_attendance' => $recentAttendance,
            'recent_violations' => array_slice($violations, 0, 5),
            'recent_payments' => array_slice($claims, 0, 5),
            'events' => array_slice($events, 0, 5)
        ];
    }
}
