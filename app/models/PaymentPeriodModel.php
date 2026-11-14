<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once dirname(__DIR__) . '/libraries/CalendarDatePolicy.php';

class PaymentPeriodModel extends Model
{
    public function periods(?int $orgId = null): array
    {
        $query = $this->db->table('organization_payment_periods');
        if ($orgId !== null) {
            $query->where('org_user_id', $orgId);
        }
        return $query->order_by('semester_start', 'DESC')
            ->get_all() ?: [];
    }

    public function fine_recording_open(int $orgId, ?int $academicId, ?string $at = null): bool
    {
        $at = $at ?? (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
        $query = $this->db->table('organization_payment_periods')->where('org_user_id', $orgId)
            ->where('opens_at', '<=', $at)->where('closes_at', '>', $at);
        if ($academicId) $query->where('academic_period_id', $academicId);
        // Legacy events have no semester reference; they must not bypass an open window.
        return $query->count() === 0;
    }

    public function require_fine_recording(int $orgId, ?int $academicId): void
    {
        if (!$this->fine_recording_open($orgId, $academicId)) throw new DomainException('New fines are locked while this organization’s payment window is open. Attendance without fines can still be recorded.', 409);
    }

    private function date_value($value, bool $withTime = false): string
    {
        $format = $withTime ? 'Y-m-d H:i:s' : 'Y-m-d';
        $value = is_string($value) ? str_replace('T', ' ', $value) : '';
        if ($withTime && strlen($value) === 16) {
            $value .= ':00';
        }
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('Asia/Manila'));
        if (!$date || $date->format($format) !== $value) {
            throw new DomainException('Enter valid payment times.', 422);
        }
        return $value;
    }

    public function save(int $orgId, array $input): void
    {
        $opens = $this->date_value($input['opens_at'] ?? null, true);
        $closes = $this->date_value($input['closes_at'] ?? null, true);
        $academic = null;
        CalendarDatePolicy::assertNotPastDay($opens, 'Payment opening date');
        CalendarDatePolicy::assertNotPastDay($closes, 'Payment closing date');

        if ($opens >= $closes) {
            throw new DomainException('Payment closing time must be after opening time.', 422);
        }
        $this->db->transaction();
        try {
            // Follow semester rollover lock order before locking the organization.
            $this->db->raw("SELECT id FROM users WHERE role='ADMIN' ORDER BY id LIMIT 1 FOR UPDATE");
            // Serialize schedule changes with fine recording and payment submissions.
            $this->db->raw('SELECT id FROM users WHERE id=? FOR UPDATE', [$orgId]);
            $existing = $this->periods($orgId);
            $id = (int) ($input['id'] ?? 0);
            $old = $id ? $this->db->table('organization_payment_periods')->where('id', $id)->where('org_user_id', $orgId)->get() : null;
            if ($id && !$old) throw new DomainException('Payment window not found.', 404);
            $academic = $this->call->model('AcademicPeriodModel')->current();
            $periodId = (int) ($old['academic_period_id'] ?? $input['academic_period_id'] ?? 0);
            if (!$academic || $academic['status'] !== 'OPEN' || (int) $academic['id'] !== $periodId) {
                throw new DomainException('Payment windows can only be set for the open current academic semester.', 409);
            }
            foreach ($existing as $other) {
                if ((int) $other['id'] !== $id && (int) ($other['academic_period_id'] ?? 0) === $periodId
                    && $opens < $other['closes_at'] && $closes > $other['opens_at']) {
                    throw new DomainException('Payment windows for this organization and semester must not overlap.', 409);
                }
            }
            $changed = !$old || $old['opens_at'] !== $opens || $old['closes_at'] !== $closes;
            if (!empty($input['id'])) {
                $id = filter_var($input['id'], FILTER_VALIDATE_INT);
                $period = array_values(array_filter($existing, static fn ($row) => (int) $row['id'] === $id));
                if (!$period) {
                    throw new DomainException('Semester not found.', 404);
                }
                $this->db->table('organization_payment_periods')
                    ->where('org_user_id', $orgId)
                    ->where('id', $id)
                    ->update([
                        'opens_at' => $opens,
                        'closes_at' => $closes,
                    ]);
            } else {
                $label = $academic['label'];
                if ($label === '' || mb_strlen($label) > 100) {
                    throw new DomainException('Enter a semester name of up to 100 characters.', 422);
                }
                // Preserve earlier coverage; new schedules automatically cover subsequent fines.
                $start = $academic['starts_on'] ?: '1000-01-01';
                $end = $academic['ends_on'] ?: '9999-12-31';
                if ($start > $end) {
                    throw new DomainException('Choose a closing date after the previous schedule’s coverage, or edit its payment window.', 409);
                }
                $sameSemester = array_values(array_filter($existing, static fn ($row) => (int) ($row['academic_period_id'] ?? 0) === $periodId));
                usort($sameSemester, static fn ($a, $b) => (int) $a['id'] <=> (int) $b['id']);
                // All windows share the original semester cap; never reset a cap per window.
                $cap = $sameSemester ? $sameSemester[0]['cap_amount'] : ($input['cap_amount'] ?? '');
                if ($cap !== '' && $cap !== null && (
                    !is_string($cap)
                    || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $cap)
                    || (float) $cap <= 0
                )) {
                    throw new DomainException('Enter a positive cap or leave it blank for no cap.', 422);
                }
                $cap = $cap === '' || $cap === null ? null : $cap;
                if ($cap !== null) {
                    $payments = $this->call->model('PaymentModel');
                    $studentIds = $this->db->table('payment_claims')
                        ->select('student_id')
                        ->where('org_user_id', $orgId)
                        ->in('status', [
                            'PENDING',
                            'CONFIRMED',
                        ])
                        ->get_all(PDO::FETCH_COLUMN);
                    $voucherStudentIds = $this->db->table('student_vouchers')
                        ->select('student_id')
                        ->where('org_user_id', $orgId)
                        ->get_all(PDO::FETCH_COLUMN);
                    $studentIds = array_merge($studentIds ?: [], $voucherStudentIds ?: []);
                    foreach (array_unique($studentIds ?: []) as $studentId) {
                        $reserved = 0;
                        foreach ($payments->fines((int) $studentId) as $fine) {
                            if (!empty($fine['legacy_assessment']) || (int) ($fine['academic_period_id'] ?? 0) !== (int) $academic['id']) continue;
                            $day = substr($fine['recorded_at'], 0, 10);
                            if ((int) $fine['org_user_id'] === $orgId && $day >= $start && $day <= $end) {
                                $reserved += (int) round((float) $fine['paid_amount'] * 100);
                                $reserved += (int) round((float) $fine['pending_amount'] * 100);
                                $reserved += (int) round((float) $fine['discount_amount'] * 100);
                            }
                        }
                        if ($reserved > (int) round((float) $cap * 100)) {
                            throw new DomainException('This cap is below a student’s confirmed payments, pending claims, and discounts in this semester. Resolve pending claims or choose a higher cap.', 409);
                        }
                    }
                }
                $this->db->table('organization_payment_periods')
                    ->insert([
                        'org_user_id' => $orgId,
                        'academic_period_id' => $academic['id'],
                        'label' => $label,
                        'semester_start' => $start,
                        'semester_end' => $end,
                        'opens_at' => $opens,
                        'closes_at' => $closes,
                        'cap_amount' => $cap,
                    ]);
            }
            if (!$id) $id = (int) $this->db->last_id();
            if ($changed) $this->call->model('NotificationModel')->payment_window($orgId, $id, $academic, $opens, $closes);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function apply(array $rows): array
    {
        $rows = $this->call->model('StudentVoucherModel')->attach($rows);
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        $rows = self::calculate($rows, $this->periods(), $now->format('Y-m-d H:i:s'));
        $periods = array_column($this->call->model('AcademicPeriodModel')->periods(), null, 'id');
        foreach ($rows as &$row) {
            $row['academic_period_label'] = $periods[$row['academic_period_id'] ?? 0]['label'] ?? 'Legacy / Unassigned';
        }
        unset($row);
        return $rows;
    }

    public static function calculate(array $rows, array $periods, string $now): array
    {
        // Prefer an open window, otherwise the next opening, then the latest closed.
        // Cap grouping below stays at semester scope regardless of the chosen window.
        usort($periods, static function ($a, $b) use ($now) {
            $rank = static fn ($p) => $now >= $p['opens_at'] && $now < $p['closes_at'] ? 0 : ($now < $p['opens_at'] ? 1 : 2);
            $ra = $rank($a); $rb = $rank($b);
            if ($ra !== $rb) return $ra <=> $rb;
            return ($ra === 2 ? strcmp($b['closes_at'], $a['closes_at']) : strcmp($a['opens_at'], $b['opens_at'])) ?: ((int) $a['id'] <=> (int) $b['id']);
        });
        $semesterCaps = [];
        $byId = $periods;
        usort($byId, static fn ($a, $b) => (int) $a['id'] <=> (int) $b['id']);
        foreach ($byId as $period) if (!empty($period['academic_period_id'])) {
            $capKey = $period['org_user_id'] . ':' . $period['academic_period_id'];
            if (!array_key_exists($capKey, $semesterCaps)) $semesterCaps[$capKey] = $period['cap_amount'];
        }
        $groups = [];
        foreach ($rows as $index => &$row) {
            $row['discount_amount'] = $row['discount_amount'] ?? '0.00';
            $row['semester_label'] = null;
            $row['payment_period_id'] = null;
            $row['payment_window'] = 'NOT_SCHEDULED';
            $row['opens_at'] = null;
            $row['closes_at'] = null;
            $row['payable_amount'] = $row['fine_amount'];
            $row['cap_adjustment'] = '0.00';
            $day = substr($row['recorded_at'], 0, 10);
            foreach ($periods as $period) {
                // Old assessments retain their original schedule/cap rules even
                // when an administrator later classifies their archive semester.
                $legacy = !empty($row['legacy_assessment']) || !array_key_exists('legacy_assessment', $row);
                if ($legacy && !empty($period['academic_period_id'])) continue;
                if (!$legacy && (empty($row['academic_period_id']) || (int) ($period['academic_period_id'] ?? 0) !== (int) $row['academic_period_id'])) continue;
                if ((int) $period['org_user_id'] !== (int) $row['org_user_id']
                    || $day < $period['semester_start']
                    || $day > $period['semester_end']) {
                    continue;
                }
                $row['semester_label'] = $period['label'];
                $row['payment_period_id'] = (int) $period['id'];
                $row['opens_at'] = $period['opens_at'];
                $row['closes_at'] = $period['closes_at'];
                $row['payment_window'] = $now < $period['opens_at'] ? 'UPCOMING' : ($now >= $period['closes_at'] ? 'CLOSED' : 'OPEN');
                $capKey = $period['org_user_id'] . ':' . ($period['academic_period_id'] ?? 0);
                $key = ($row['student_id'] ?? 0) . ':' . ($legacy ? 'window:' . $period['id'] : 'semester:' . $capKey);
                $groups[$key]['cap'] = $legacy ? $period['cap_amount'] : $semesterCaps[$capKey];
                $groups[$key]['indexes'][] = $index;
                break;
            }
        }
        unset($row);
        foreach ($groups as $group) {
            if ($group['cap'] === null) {
                continue;
            }
            $remaining = (int) round((float) $group['cap'] * 100);
            // Existing claims keep their allocations. Allocate the remaining cap oldest first.
            foreach ($group['indexes'] as $index) {
                $reserved = (int) round(((float) $rows[$index]['paid_amount'] + (float) $rows[$index]['pending_amount']) * 100);
                $reserved += (int) round((float) $rows[$index]['discount_amount'] * 100);
                $rows[$index]['payable_amount'] = number_format($reserved / 100, 2, '.', '');
                $remaining -= $reserved;
            }
            $indexes = $group['indexes'];
            usort($indexes, static function ($a, $b) use ($rows) {
                return [$rows[$a]['recorded_at'], $rows[$a]['fine_key']]
                    <=> [$rows[$b]['recorded_at'], $rows[$b]['fine_key']];
            });
            foreach ($indexes as $index) {
                $gross = (int) round((float) $rows[$index]['fine_amount'] * 100);
                $reserved = (int) round((float) $rows[$index]['payable_amount'] * 100);
                $extra = min(max(0, $remaining), max(0, $gross - $reserved));
                $rows[$index]['payable_amount'] = number_format(($reserved + $extra) / 100, 2, '.', '');
                $rows[$index]['cap_adjustment'] = number_format(max(0, $gross - $reserved - $extra) / 100, 2, '.', '');
                $remaining -= $extra;
            }
        }
        foreach ($rows as &$row) {
            $payable = (int) round((float) $row['payable_amount'] * 100);
            $payable = max(0, $payable - (int) round((float) $row['discount_amount'] * 100));
            $row['payable_amount'] = number_format($payable / 100, 2, '.', '');
            $paid = (int) round((float) $row['paid_amount'] * 100);
            $pending = (int) round((float) $row['pending_amount'] * 100);
            $row['outstanding'] = number_format(max(0, $payable - $paid) / 100, 2, '.', '');
            $row['available_to_claim'] = number_format(max(0, $payable - $paid - $pending) / 100, 2, '.', '');
            $row['payment_status'] = $paid >= $payable ? 'PAID' : 'UNPAID';
        }
        unset($row);
        return $rows;
    }
}
