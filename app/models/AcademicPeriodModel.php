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
    public function enrollments(int $studentId): array
    {
        return $this->db->table('student_enrollments e')->select('e.id,e.student_id,e.academic_period_id,e.course,e.year_level,e.section,e.enrollment_status,p.label,p.starts_on,p.ends_on')
            ->join('academic_periods p', 'p.id=e.academic_period_id')->where('e.student_id', '=', $studentId)->order_by('p.year_start', 'DESC')->order_by('p.id', 'DESC')->get_all();
    }
    public function require_enrolled(int $studentId, ?int $periodId): void
    {
        if (!$periodId) return; // Existing legacy event rosters remain valid.
        $this->period($periodId, true);
        $enrollment = $this->db->table('student_enrollments')->where('student_id', '=', $studentId)
            ->where('academic_period_id', '=', $periodId)->where('enrollment_status', '=', 'ENROLLED')->get();
        if (!$enrollment) throw new DomainException('Student is not enrolled in this academic semester. Ask the administrator to update semester enrollment.', 409);
    }
    public function enroll(int $studentId, array $input, int $adminId): void
    {
        $mode = $input['mode'] ?? 'ENROLL';
        if (!in_array($mode, ['ENROLL', 'CORRECT'], true)) throw new DomainException('Choose enroll or correct semester details.', 422);
        $period = $this->period((int) ($input['academic_period_id'] ?? 0), $mode === 'ENROLL');
        $course = strtoupper(trim((string) ($input['course'] ?? '')));
        $year = $input['year_level'] ?? '';
        $section = strtoupper(trim((string) ($input['section'] ?? '')));
        $status = $input['enrollment_status'] ?? 'ENROLLED';
        $programs = $this->call->model('StudentModel')->programs();
        if (!in_array($course, array_merge(array_keys($programs), ['CCS', 'CAS', 'CRIM']), true)
            || !in_array($year, ['1st Year', '2nd Year', '3rd Year', '4th Year'], true)
            || !preg_match('/^[A-Z]+[1-9][0-9]*$/', $section) || strlen($section) > 50
            || !in_array($status, ['ENROLLED', 'WITHDRAWN', 'COMPLETED'], true)) throw new DomainException('Enter valid program, year level, section and enrollment status.', 422);
        $this->db->transaction();
        try {
            $student = $this->db->raw('SELECT id,course,year_level,section,current_academic_period_id FROM students WHERE id=? FOR UPDATE', [$studentId])->fetch(PDO::FETCH_ASSOC);
            if (!$student) throw new DomainException('Student not found.', 404);
            $old = $this->db->table('student_enrollments')->where('student_id', '=', $studentId)->where('academic_period_id', '=', $period['id'])->get();
            if (($mode === 'ENROLL' && $old) || ($mode === 'CORRECT' && !$old)) throw new DomainException($old ? 'Already enrolled in this semester. Choose Correct to edit it.' : 'No enrollment exists in this semester. Choose Enroll first.', 409);
            $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
            $values = ['course' => $course, 'year_level' => $year, 'section' => $section, 'enrollment_status' => $status, 'updated_at' => $now];
            if ($old) $this->db->table('student_enrollments')->where('id', '=', $old['id'])->update($values);
            else $this->db->table('student_enrollments')->insert($values + ['student_id' => $studentId, 'academic_period_id' => $period['id'], 'created_at' => $now]);
            $current = !empty($input['make_current']);
            if ($current || (int) ($student['current_academic_period_id'] ?? 0) === (int) $period['id']) {
                $this->db->table('students')->where('id', '=', $studentId)->update(['course' => $course, 'year_level' => $year, 'section' => $section, 'current_academic_period_id' => $period['id']]);
            }
            $this->db->table('student_change_history')->insert(['student_id' => $studentId, 'changed_by' => $adminId,
                'batch_id' => bin2hex(random_bytes(16)), 'reason' => $old ? 'SEMESTER_CORRECTION' : 'SEMESTER_ENROLLMENT',
                'note' => 'Enrollment snapshot; previous fine and payment periods unchanged.',
                'before_values' => json_encode($old ?: [], JSON_THROW_ON_ERROR),
                'after_values' => json_encode($values + ['academic_period_id' => $period['id'], 'make_current' => $current], JSON_THROW_ON_ERROR), 'changed_at' => $now]);
            $this->db->commit();
        } catch (Throwable $error) { $this->db->roll_back(); throw $error; }
    }
    public function sync_current_enrollment(int $studentId): void
    {
        $student = $this->db->table('students')->where('id', '=', $studentId)->get();
        if (empty($student['current_academic_period_id'])) return;
        $this->period((int) $student['current_academic_period_id'], true);
        $this->db->table('student_enrollments')->where('student_id', '=', $studentId)
            ->where('academic_period_id', '=', $student['current_academic_period_id'])
            ->update(array_intersect_key($student, array_flip(['course', 'year_level', 'section'])));
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
