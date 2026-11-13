<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
class AcademicPeriodModel extends Model
{
    public function periods(): array
    {
        return $this->db->table('academic_periods')->order_by('year_start', 'DESC')->order_by('id', 'DESC')->get_all();
    }
    public function period(int $id, bool $open = false): array
    {
        $period = $this->db->table('academic_periods')->where('id', '=', $id)->get();
        if (!$period) throw new DomainException('Choose a valid academic year and semester.', 422);
        if ($open && $period['status'] !== 'OPEN') throw new DomainException('This academic semester is closed.', 409);
        return $period;
    }
    public function current(): ?array
    {
        return $this->db->table('academic_periods')->where('is_current', '=', 1)->get() ?: null;
    }
    public function recording_period($requested, string $day): int
    {
        $period = $requested ? $this->period((int) $requested, true) : $this->current();
        if (!$period || $period['status'] !== 'OPEN') throw new DomainException('Ask the administrator to set an open academic semester first.', 409);
        if (($period['starts_on'] && $day < $period['starts_on']) || ($period['ends_on'] && $day > $period['ends_on'])) throw new DomainException('The record date must fall within the selected academic semester.', 422);
        return (int) $period['id'];
    }
    public function save(array $input): array
    {
        $year = filter_var($input['year_start'] ?? null, FILTER_VALIDATE_INT);
        $term = $input['term'] ?? '';
        $terms = ['FIRST' => 'First Semester', 'SECOND' => 'Second Semester', 'SUMMER' => 'Summer Term'];
        if (!$year || $year < 2000 || $year > 2200 || !is_string($term) || !isset($terms[$term])) throw new DomainException('Choose a valid academic year and semester.', 422);
        foreach (['starts_on', 'ends_on'] as $key) {
            $value = is_string($input[$key] ?? null) ? $input[$key] : '';
            if ($value === '') { $input[$key] = null; continue; }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Manila'));
            if (!$date || $date->format('Y-m-d') !== $value) throw new DomainException('Enter valid academic period dates.', 422);
            if (empty($input['id'])) {
                require_once dirname(__DIR__) . '/libraries/CalendarDatePolicy.php';
                CalendarDatePolicy::assertNotPastDay($value, 'Academic period date');
            }
        }
        if ($input['starts_on'] && $input['ends_on'] && $input['starts_on'] > $input['ends_on']) throw new DomainException('Academic period end must follow its start.', 422);
        $this->db->transaction();
        try {
            // The same admin lock serializes calendar writes and current-term changes.
            $this->db->raw("SELECT id FROM users WHERE role='ADMIN' ORDER BY id LIMIT 1 FOR UPDATE");
            $id = (int) ($input['id'] ?? 0);
            if ($id) {
                $old = $this->period($id);
                if ($old['year_start'] != $year || $old['term'] !== $term || $old['starts_on'] !== $input['starts_on'] || $old['ends_on'] !== $input['ends_on']) {
                    throw new DomainException('Existing semester identity and dates are fixed. Only its status/current selection may change.', 409);
                }
            }
            $status = $input['status'] ?? 'OPEN';
            if (!in_array($status, ['OPEN', 'CLOSED'], true)) throw new DomainException('Choose Open or Closed.', 422);
            $current = !empty($input['is_current']) ? 1 : 0;
            if ($current && $status !== 'OPEN') throw new DomainException('The current semester must be open.', 422);
            $duplicate = $this->db->table('academic_periods')->select('id')->where('id', '<>', $id)->where('year_start', '=', $year)->where('term', '=', $term)->get();
            if ($duplicate) throw new DomainException('This academic year and semester already exists.', 409);
            if ($current) $this->db->table('academic_periods')->where('is_current', '=', 1)->update(['is_current' => 0]);
            $values = ['year_start' => $year, 'term' => $term, 'label' => $year . '–' . ($year + 1) . ' · ' . $terms[$term],
                'starts_on' => $input['starts_on'], 'ends_on' => $input['ends_on'], 'status' => $status, 'is_current' => $current];
            if ($id) $this->db->table('academic_periods')->where('id', '=', $id)->update($values);
            else { $this->db->table('academic_periods')->insert($values); $id = (int) $this->db->last_id(); }
            $this->db->commit(); return ['id' => $id];
        } catch (Throwable $error) { $this->db->roll_back(); throw $error; }
    }
    public function require_student_period(int $studentId, ?int $periodId): void
    {
        if (!$periodId) return;
        $this->period($periodId, true);
        $student = $this->db->table('students')->select('current_academic_period_id')->where('id', $studentId)->get();
        if (!$student || (int) $student['current_academic_period_id'] !== $periodId) throw new DomainException('Student semester does not match this record. Update the student data first.', 409);
    }
    public function legacy_sources(): array
    {
        return ['events' => $this->db->table('org_events e')->select('e.id,e.name,e.org_user_id,e.scan_ends_at,u.organization_name')
            ->join('users u', 'u.id=e.org_user_id')->where_null('e.academic_period_id')->order_by('e.id', 'DESC')->limit(500)->get_all(),
            'violations' => $this->db->table('org_violation_records v')->select('v.id,v.violation_name,v.recorded_at,s.student_number,u.organization_name')
                ->join('students s', 's.id=v.student_id')->join('users u', 'u.id=v.org_user_id')
                ->where_null('v.academic_period_id')->where_null('v.event_id')->order_by('v.id', 'DESC')->limit(500)->get_all()];
    }
    public function classify(array $input, int $adminId): array
    {
        $period = $this->period((int) ($input['academic_period_id'] ?? 0));
        $kind = $input['kind'] ?? '';
        if (!in_array($kind, ['event', 'violation'], true) || !isset($input['preview']) || !is_bool($input['preview'])) throw new DomainException('Preview a valid legacy source first.', 422);
        $id = (int) ($input['source_id'] ?? 0);
        $table = $kind === 'event' ? 'org_events' : 'org_violation_records';
        $source = $this->db->table($table)->where('id', '=', $id)->get();
        if (!$source || !empty($source['academic_period_id'])) throw new DomainException('Source is missing or already classified.', 409);
        $this->db->transaction();
        try {
            $this->db->raw('SELECT id FROM users WHERE id=? FOR UPDATE', [$source['org_user_id']]);
            $source = $this->db->raw('SELECT * FROM ' . $table . ' WHERE id=? FOR UPDATE', [$id])->fetch(PDO::FETCH_ASSOC);
            if (!$source || !empty($source['academic_period_id'])) throw new DomainException('Source changed. Reload the legacy list.', 409);
            if ($kind === 'event' && $source['scan_ends_at'] >= (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s')) throw new DomainException('Wait until this event ends before classifying legacy records.', 409);
            if ($kind === 'violation' && $source['event_id'] !== null) throw new DomainException('Classify the parent event instead.', 422);
            $scans = $kind === 'event' ? $this->db->table('org_scan_records')->select('id')->where('event_id', '=', $id)->where_null('academic_period_id')->get_all(PDO::FETCH_COLUMN) : [];
            $violations = $kind === 'event' ? $this->db->table('org_violation_records')->select('id')->where('event_id', '=', $id)->where_null('academic_period_id')->get_all(PDO::FETCH_COLUMN) : [$id];
            $details = ['source' => $source, 'scans' => $scans, 'violations' => $violations, 'period' => $period['id']];
            $fingerprint = hash('sha256', json_encode($details, JSON_THROW_ON_ERROR));
            if (!$input['preview']) {
                if (!hash_equals($fingerprint, (string) ($input['fingerprint'] ?? ''))) throw new DomainException('Classification preview is stale. Preview again.', 409);
                $this->db->table($table)->where('id', '=', $id)->update(['academic_period_id' => $period['id']]);
                if ($scans) $this->db->table('org_scan_records')->in('id', $scans)->update(['academic_period_id' => $period['id']]);
                if ($violations) $this->db->table('org_violation_records')->in('id', $violations)->update(['academic_period_id' => $period['id']]);
                // legacy_assessment, fine amounts, allocations, source IDs and
                // notification source keys deliberately remain unchanged.
                $this->db->table('academic_record_changes')->insert(['changed_by' => $adminId, 'academic_period_id' => $period['id'], 'source_kind' => $kind,
                    'source_id' => $id, 'change_details' => json_encode($details, JSON_THROW_ON_ERROR),
                    'changed_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s')]);
            }
            $this->db->commit();
            return ['fingerprint' => $fingerprint, 'scans' => count($scans), 'violations' => count($violations), 'period' => $period['label'],
                'message' => $input['preview'] ? 'Review classification. Balances, payment allocations, notifications and cap rules will stay unchanged.' : 'Legacy records classified.'];
        } catch (Throwable $error) { $this->db->roll_back(); throw $error; }
    }
}
