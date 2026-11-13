<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AttendanceModel extends Model
{
    public function snapshot(int $eventId, int $orgId): void
    {
        $org = $this->call->model('OrgEventModel')->organization($orgId);
        $params = [
            $eventId
        ];
        $where = "account_status='ACTIVE' AND activation_status='ACTIVATED'";
        $event = $this->db->table('org_events')->select('academic_period_id')->where('id', '=', $eventId)->get();
        if (!empty($event['academic_period_id'])) {
            $where .= " AND current_academic_period_id=?";
            $params[] = $event['academic_period_id'];
        }
        if ($org['allowed_courses'] !== null) {
            $where .= ' AND UPPER(TRIM(course)) IN (' . implode(',', array_fill(0, count($org['allowed_courses']), '?')) . ')';
            array_push($params,...$org['allowed_courses']);
        }
        // INSERT ... SELECT snapshots the roster atomically; IGNORE makes retries idempotent.
        $this->db->raw('INSERT IGNORE INTO org_event_roster (event_id,student_id) SELECT ?,id FROM students WHERE ' . $where, $params);
    }

    public function finalize(?int $orgId = null): int
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
        $query = $this->db->table('org_events')
            ->select('id,org_user_id')
            ->where('event_type', 'ATTENDANCE')
            ->where('absence_enabled', 1)
            ->where_null('attendance_finalized_at')
            ->where('scan_ends_at', '<', $now);
        if ($orgId !== null) {
            $query->where('org_user_id', $orgId);
        }
        $events = $query->get_all();
        $total = 0;
        foreach ($events as $item) {
            $this->db->transaction();
            try {
                // Same organization/event lock order as scan confirmation prevents races.
                $this->db->raw('SELECT id FROM users WHERE id=? FOR UPDATE', [
                    $item['org_user_id']
                ]);
                $event = $this->db->raw('SELECT id, org_user_id, scan_ends_at, attendance_finalized_at, absence_fine_amount, academic_period_id FROM org_events WHERE id=? FOR UPDATE', [
                    $item['id']
                ])->fetch(PDO::FETCH_ASSOC);
                if (!$event || $event['attendance_finalized_at'] !== null || $event['scan_ends_at'] >= $now) {
                    $this->db->commit();
                    continue;
                }
                $insert = $this->db->raw("INSERT IGNORE INTO org_scan_records (event_id,student_id,recorded_by,record_type,fine_amount,recorded_at,attendance_status,academic_period_id,legacy_assessment)
                SELECT r.event_id,r.student_id,?,'ATTENDANCE',?,?,'ABSENT',?,? FROM org_event_roster r
                LEFT JOIN org_scan_records s ON s.event_id=r.event_id AND s.student_id=r.student_id
                WHERE r.event_id=? AND s.id IS NULL", [
                    $event['org_user_id'],
                    $event['absence_fine_amount'],
                    $event['scan_ends_at'],
                    $event['academic_period_id'],
                    empty($event['academic_period_id']) ? 1 : 0,
                    $event['id']
                ]);
                $total += $insert->rowCount();
                $this->call->model('NotificationModel')
                    ->record_absences((int) $event['id']);
                $this->db->table('org_events')
                    ->where('id', '=', $event['id'])
                    ->update([
                        'attendance_finalized_at' => $now
                    ]);
                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->roll_back();
                throw $e;
            }
        }
        return $total;
    }
}
