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
        CalendarDatePolicy::assertNotPastDay($opens, 'Payment opening date');
        CalendarDatePolicy::assertNotPastDay($closes, 'Payment closing date');
        if ($opens >= $closes) {
            throw new DomainException('Payment closing time must be after opening time.', 422);
        }
        $this->db->transaction();
        try {
            // Serialize schedule changes with payment submissions for this organization.
            $this->db->raw('SELECT id FROM users WHERE id=? FOR UPDATE', [$orgId]);
            $existing = $this->periods($orgId);
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
                $label = is_string($input['label'] ?? null) ? trim($input['label']) : '';
                if ($label === '' || mb_strlen($label) > 100) {
                    throw new DomainException('Enter a semester name of up to 100 characters.', 422);
                }
                // Preserve earlier coverage; new schedules automatically cover subsequent fines.
                $start = '1000-01-01';
                foreach ($existing as $period) {
                    $next = (new DateTimeImmutable($period['semester_end']))
                        ->modify('+1 day')
                        ->format('Y-m-d');
                    $start = max($start, $next);
                }
                $end = substr($closes, 0, 10);
                if ($start > $end) {
                    throw new DomainException('Choose a closing date after the previous schedule’s coverage, or edit its payment window.', 409);
                }
                $cap = $input['cap_amount'] ?? '';
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
                        'label' => $label,
                        'semester_start' => $start,
                        'semester_end' => $end,
                        'opens_at' => $opens,
                        'closes_at' => $closes,
                        'cap_amount' => $cap,
                    ]);
            }
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
        return self::calculate($rows, $this->periods(), $now->format('Y-m-d H:i:s'));
    }

    public static function calculate(array $rows, array $periods, string $now): array
    {
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
                $key = ($row['student_id'] ?? 0) . ':' . $period['id'];
                $groups[$key]['cap'] = $period['cap_amount'];
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
