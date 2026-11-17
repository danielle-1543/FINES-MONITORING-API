<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class OrganizationDashboardModel extends Model
{
    public function overview(int $id): array
    {
        $period = $this->call->model('AcademicPeriodModel')->current();
        $periodId = (int) ($period['id'] ?? -1);
        $orgs = $this->call->model('OrgEventModel');
        $org = $orgs->organization($id);
        $this->call->model('AttendanceModel')->finalize($id);
        $studentsQuery = $this->db->table('students s');
        $this->scope_students($studentsQuery, $org['allowed_courses']);
        $students = $studentsQuery->count();

        $attendanceQuery = $this->attendance_query($id, $org['allowed_courses']);
        $attendance = $attendanceQuery
            ->select("SUM(r.attendance_status='PRESENT') AS present, SUM(r.attendance_status='ABSENT') AS absent")
            ->get();

        $recentAttendance = $this->attendance_query($id, $org['allowed_courses'])
            ->select('r.id, s.full_name, s.student_number, e.name AS event_name, r.attendance_status, r.recorded_at')
            ->order_by('r.recorded_at', 'DESC')
            ->order_by('r.id', 'DESC')
            ->limit(5)
            ->get_all();
        $payments = $this->call->model('PaymentModel');
        $ledger = $payments->organization_fines($id, [], true);
        $claims = array_values(array_filter($payments->organization_claims($id),
            static fn ($claim) => (int) ($claim['academic_period_id'] ?? 0) === $periodId));
        $violations = [];
        $carriedCents = 0;
        foreach ($ledger['data'] as $student) {
            foreach ($student['fines'] as $fine) {
                if (!empty($fine['is_carried_balance'])) {
                    $carriedCents += (int) round((float) $fine['outstanding'] * 100);
                }
                if ($fine['record_type'] === 'VIOLATION' && empty($fine['is_carried_balance'])) {
                    $violations[] = $fine + ['full_name' => $student['full_name'], 'student_number' => $student['student_number']];
                }
            }
        }
        usort($violations, fn ($a, $b) => strcmp($b['recorded_at'], $a['recorded_at']) ?: strcmp($b['fine_key'], $a['fine_key']));
        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
        $events = $this->db->table('org_events')
            ->select('id,name,scan_starts_at,scan_ends_at')
            ->where('org_user_id', '=', $id)
            ->where('academic_period_id', $periodId)
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
            'academic_period' => $period,
            'summary' => [
                'students' => $students,
                'present' => (int) $attendance['present'],
                'absent' => (int) $attendance['absent'],
                'violations' => count($violations),
                'open_events' => $open,
                'carried_balance' => number_format($carriedCents / 100, 2, '.', ''),
                'pending_claims' => $pending
            ] + $ledger['summary'],
            'recent_attendance' => $recentAttendance,
            'recent_violations' => array_slice($violations, 0, 5),
            'recent_payments' => array_slice($claims, 0, 5),
            'events' => array_slice($events, 0, 5)
        ];
    }

    private function scope_students($query, ?array $courses): void
    {
        if ($courses !== null) {
            // The students.course column uses a case-insensitive collation.
            $query->in('TRIM(s.course)', $courses ?: ['']);
        }
    }

    private function attendance_query(int $orgId, ?array $courses)
    {
        $query = $this->db->table('org_scan_records r')
            ->join('org_events e', 'e.id=r.event_id')
            ->join('students s', 's.id=r.student_id')
            ->where('e.org_user_id', $orgId)
            ->where('r.record_type', 'ATTENDANCE')
            ->where('r.academic_period_id', $this->call->model('AcademicPeriodModel')->current()['id'] ?? -1);
        $this->scope_students($query, $courses);
        return $query;
    }
}
