<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class StudentVoucherModel extends Model
{
    private function cents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    public function student(int $orgId, int $studentId): array
    {
        $org = $this->call->model('OrgEventModel')->organization($orgId);
        $student = $this->db->table('students')
            ->select('id, student_number, full_name, course, year_level, section, account_status, activation_status')
            ->where('id', $studentId)
            ->get();
        $courses = $org['allowed_courses'];
        if (!$student || ($courses !== null && !in_array(strtoupper(trim($student['course'])), array_map('strtoupper', $courses), true))) {
            throw new DomainException('Student not found in your organization.', 404);
        }
        return $student;
    }

    public function record(int $orgId, int $studentId): array
    {
        $student = $this->student($orgId, $studentId);
        $fines = array_values(array_filter(
            $this->call->model('PaymentModel')->fines($studentId),
            static fn ($fine) => (int) $fine['org_user_id'] === $orgId
        ));
        $summary = [];
        foreach (['fine_amount', 'cap_adjustment', 'discount_amount', 'paid_amount', 'pending_amount', 'outstanding', 'available_to_claim'] as $key) {
            $total = 0;
            foreach ($fines as $fine) {
                $total += $this->cents((string) $fine[$key]);
            }
            $summary[$key] = number_format($total / 100, 2, '.', '');
        }
        $vouchers = $this->db->table('student_vouchers')
            ->select('id, discount_type, discount_value, amount, reason, created_at')
            ->where('org_user_id', $orgId)
            ->where('student_id', $studentId)
            ->order_by('id', 'DESC')
            ->get_all();
        return ['student' => $student, 'fines' => $fines, 'summary' => $summary, 'vouchers' => $vouchers];
    }

    public function attach(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $items = $this->db->table('student_voucher_items i')
            ->select('i.fine_key')
            ->select_sum('i.amount', 'discount_amount')
            ->in('i.fine_key', array_values(array_unique(array_column($rows, 'fine_key'))))
            ->group_by('i.fine_key')
            ->get_all();
        $amounts = array_column($items, 'discount_amount', 'fine_key');
        foreach ($rows as &$row) {
            $row['discount_amount'] = $amounts[$row['fine_key']] ?? '0.00';
        }
        unset($row);
        return $rows;
    }

    public function save(int $orgId, int $studentId, array $data): void
    {
        $type = $data['discount_type'] ?? '';
        $value = $data['discount_value'] ?? null;
        $reason = is_string($data['reason'] ?? null) ? trim($data['reason']) : '';
        $requestKey = $data['request_key'] ?? '';
        if (!in_array($type, ['PESO', 'PERCENT'], true)
            || !is_string($value) || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $value)
            || $this->cents($value) <= 0 || ($type === 'PERCENT' && $this->cents($value) > 10000)) {
            throw new DomainException('Enter a positive peso amount or a percentage up to 100.', 422);
        }
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new DomainException('A reason of up to 1,000 characters is required.', 422);
        }
        if (!is_string($requestKey) || !preg_match('/^[a-f0-9-]{36}$/i', $requestKey)) {
            throw new DomainException('Invalid voucher request. Reopen the form.', 422);
        }
        $this->db->transaction();
        try {
            // Use the same lock order as payments. The builder has no FOR UPDATE.
            $this->db->raw('SELECT id FROM users WHERE id=? FOR UPDATE', [$orgId]);
            $this->db->raw('SELECT id FROM students WHERE id=? FOR UPDATE', [$studentId]);
            $this->student($orgId, $studentId);
            $existing = $this->db->table('student_vouchers')
                ->where('org_user_id', $orgId)
                ->where('request_key', $requestKey)
                ->get();
            if ($existing) {
                if ((int) $existing['student_id'] !== $studentId || $existing['discount_type'] !== $type
                    || $this->cents($existing['discount_value']) !== $this->cents($value) || $existing['reason'] !== $reason) {
                    throw new DomainException('This voucher request was already used. Reopen the form.', 409);
                }
                $this->db->commit();
                return;
            }
            $record = $this->record($orgId, $studentId);
            $available = $this->cents($record['summary']['available_to_claim']);
            if (($data['expected_available'] ?? null) !== $record['summary']['available_to_claim']) {
                throw new DomainException('The balance changed. Refresh the student record before adding a voucher.', 409);
            }
            $amount = $type === 'PESO' ? $this->cents($value) : intdiv($available * $this->cents($value) + 5000, 10000);
            if ($amount <= 0 || $amount > $available) {
                throw new DomainException('Discount must not exceed unpaid fines after pending claims.', 422);
            }
            $this->db->table('student_vouchers')->insert([
                'org_user_id' => $orgId,
                'student_id' => $studentId,
                'request_key' => $requestKey,
                'discount_type' => $type,
                'discount_value' => $value,
                'amount' => number_format($amount / 100, 2, '.', ''),
                'reason' => $reason,
                'created_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s')
            ]);
            $voucherId = (int) $this->db->last_id();
            $fines = $record['fines'];
            usort($fines, static fn ($a, $b) => [$a['recorded_at'], $a['fine_key']] <=> [$b['recorded_at'], $b['fine_key']]);
            foreach ($fines as $fine) {
                $allocation = min($amount, $this->cents($fine['available_to_claim']));
                if ($allocation <= 0) {
                    continue;
                }
                $this->db->table('student_voucher_items')->insert([
                    'voucher_id' => $voucherId,
                    'fine_key' => $fine['fine_key'],
                    'amount' => number_format($allocation / 100, 2, '.', '')
                ]);
                $amount -= $allocation;
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }
}
