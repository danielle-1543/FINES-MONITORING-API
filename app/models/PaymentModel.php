<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class PaymentModel extends Model
{
    public function allocated_payments(): string
    {
        return "SELECT p.student_id,p.org_user_id,p.fine_key,p.amount,p.status FROM payment_claims p
            WHERE NOT EXISTS(SELECT 1 FROM payment_claim_items i WHERE i.claim_id=p.id)
            UNION ALL SELECT p.student_id,p.org_user_id,i.fine_key,i.amount,p.status FROM payment_claims p JOIN payment_claim_items i ON i.claim_id=p.id";
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
    }

    private function cents($amount): int
    {
        if (!is_string($amount) || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $amount)) {
            throw new DomainException('Enter a valid payment amount.', 422);
        }
        $parts = explode('.', $amount);
        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    private function decimal(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private function student(int $userId, bool $lock = false): array
    {
        if ($lock) {
            // FOR UPDATE serializes payment claims for the same student.
            $row = $this->db->raw("SELECT s.id, s.course FROM students s JOIN users u ON u.id=s.user_id WHERE u.id=? AND u.status='ACTIVE'
                AND u.role='STUDENT' AND s.account_status='ACTIVE' AND s.activation_status='ACTIVATED' FOR UPDATE", [
                $userId
            ])->fetch(PDO::FETCH_ASSOC);
        } else {
            $row = $this->db->table('students s')
                ->select('s.id,s.course')
                ->join('users u', 'u.id=s.user_id')
                ->where('u.id', $userId)
                ->where('u.status', 'ACTIVE')
                ->where('u.role', 'STUDENT')
                ->where('s.account_status', 'ACTIVE')
                ->where('s.activation_status', 'ACTIVATED')
                ->get();
        }
        if (!$row) {
            throw new DomainException('Active student account required.', 403);
        }
        return $row;
    }

    public function fines(int $studentId): array
    {
        // UNION combines attendance and standalone violations; the derived table totals allocations.
        // The installed builder has no UNION/subquery builder, so values remain bound here.
        $sql = "SELECT CONCAT('scan-',r.id) fine_key,e.org_user_id,CASE WHEN r.record_type='ATTENDANCE' THEN 'Attendance fine' ELSE v.name END violation_name,e.name event_name,r.fine_amount,r.recorded_at,u.organization_name,r.record_type
            FROM org_scan_records r JOIN org_events e ON e.id=r.event_id JOIN users u ON u.id=e.org_user_id
            LEFT JOIN org_violation_types v ON v.id=e.violation_type_id WHERE r.student_id=? AND (r.record_type='VIOLATION' OR r.fine_amount>0)
            UNION ALL SELECT CONCAT('violation-',r.id),r.org_user_id,r.violation_name,COALESCE(e.name,'Standalone violation'),r.fine_amount,r.recorded_at,u.organization_name,'VIOLATION'
            FROM org_violation_records r JOIN users u ON u.id=r.org_user_id LEFT JOIN org_events e ON e.id=r.event_id WHERE r.student_id=?";
        $rows = $this->db->raw('SELECT f.*,COALESCE(p.paid,0) paid_amount,COALESCE(p.pending,0) pending_amount FROM (' . $sql . ") f LEFT JOIN
            (SELECT fine_key,SUM(CASE WHEN status='CONFIRMED' THEN amount ELSE 0 END) paid,SUM(CASE WHEN status='PENDING' THEN amount ELSE 0 END) pending
            FROM (" . $this->allocated_payments() . ") allocated WHERE student_id=? GROUP BY fine_key) p ON p.fine_key=f.fine_key ORDER BY f.recorded_at DESC,f.fine_key", [
            $studentId,
            $studentId,
            $studentId
        ])->fetchAll(PDO::FETCH_ASSOC);
        return $this->call->model('PaymentPeriodModel')->apply($rows);
    }

    private function claims(string $ownerColumn, int $ownerId): array
    {
        $columns = 'p.id, p.student_id, p.org_user_id, p.fine_key, p.amount, p.method, '
            . 'p.reference_number, p.status, p.submitted_at, p.reviewed_at, p.review_note, '
            . 'p.receipt_number, p.proof_file, s.full_name, s.student_number, u.organization_name';
        $rows = $this->db->table('payment_claims p')
            ->select($columns)
            ->select_count('i.fine_key', 'fine_count')
            ->join('students s', 's.id=p.student_id')
            ->join('users u', 'u.id=p.org_user_id')
            ->left_join('payment_claim_items i', 'i.claim_id=p.id')
            ->where($ownerColumn, $ownerId)
            ->group_by(explode(', ', $columns))
            ->order_by('p.id', 'DESC')
            ->get_all();
        foreach ($rows as &$row) {
            $row['has_proof'] = $row['proof_file'] !== null ? 1 : 0;
            unset($row['proof_file']);
        }
        unset($row);
        return $rows;
    }

    public function overview(int $userId): array
    {
        $student = $this->student($userId);
        $fines = $this->fines((int) $student['id']);
        $total = 0;
        $paid = 0;
        $adjustment = 0;
        $discount = 0;
        foreach ($fines as $fine) {
            $total += $this->cents((string) $fine['fine_amount']);
            $paid += $this->cents((string) $fine['paid_amount']);
            $adjustment += $this->cents((string) $fine['cap_adjustment']);
            $discount += $this->cents((string) $fine['discount_amount']);
        }
        return [
            'fines' => $fines,
            'claims' => $this->claims('p.student_id', (int) $student['id']),
            'summary' => [
                'total_fines' => $this->decimal($total),
                'paid_amount' => $this->decimal($paid),
                'cap_adjustment' => $this->decimal($adjustment),
                'discount_amount' => $this->decimal($discount),
                'outstanding' => $this->decimal(max(0, $total - $paid - $adjustment - $discount))
            ]
        ];
    }

    private function own_fine(int $studentId, string $key): array
    {
        foreach ($this->fines($studentId) as $fine) {
            if ($fine['fine_key'] === $key) {
                return $fine;
            }
        }
        throw new DomainException('Fine not found for this student.', 404);
    }

    public function submit(int $userId, array $data, ?array $proof = null, ?string $xenditReference = null): int
    {
        $key = $data['fine_key'] ?? '';
        if ($key !== 'ALL') {
            throw new DomainException('Pay the full payable balance for an organization.', 422);
        }
        $method = $xenditReference !== null ? 'XENDIT_TEST' : ($data['method'] ?? '');
        $reference = trim(is_string($data['reference_number'] ?? '') ? ($data['reference_number'] ?? '') : '');
        if (!is_string($key) || ($xenditReference === null && !in_array($method, [
            'CASH',
            'GCASH'
        ], true))) {
            throw new DomainException('Select a fine and Cash or GCash.', 422);
        }
        $amount = $xenditReference !== null ? 0 : $this->cents($data['amount'] ?? null);
        if ($xenditReference === null && $amount <= 0) {
            throw new DomainException('Amount must be greater than zero.', 422);
        }
        if (strlen($reference) > 100 || ($method === 'GCASH' && !preg_match('/^[A-Za-z0-9-]{6,100}$/', $reference))) {
            throw new DomainException('Enter a valid GCash reference number.', 422);
        }
        if ($method === 'GCASH' && !$proof) {
            throw new DomainException('Upload GCash proof of payment.', 422);
        }
        $this->student($userId);
        $paymentOrgId = (int) ($data['organization_id'] ?? 0);
        $this->db->transaction();
        try {
            // Lock before reading balances so schedule edits and submissions cannot race.
            $this->db->raw('SELECT id FROM users WHERE id=? FOR UPDATE', [$paymentOrgId]);
            $student = $this->student($userId, true);
            $items = [];
            if ($key === 'ALL') {
                $orgId = filter_var($data['organization_id'] ?? null, FILTER_VALIDATE_INT, [
                    'options' => [
                        'min_range' => 1
                    ]
                ]);
                if (!$orgId) {
                    throw new DomainException('Select an organization.', 422);
                }
                $org = $this->org((int) $orgId);
                if (!$this->org_scope($org, $student['course'])) {
                    throw new DomainException('Organization does not cover your program.', 403);
                }
                $total = 0;
                foreach ($this->fines((int) $student['id']) as $candidate) {
                    if ((int) $candidate['org_user_id'] === $orgId && $this->cents((string) $candidate['pending_amount']) > 0) {
                        throw new DomainException('An existing payment claim is pending for this organization. Wait for its review or cancel it before paying.', 409);
                    }
                    if ((int) $candidate['org_user_id'] !== $orgId || $candidate['payment_window'] !== 'OPEN') {
                        continue;
                    }
                    $available = $this->cents($candidate['available_to_claim']);
                    if ($available > 0) {
                        $items[$candidate['fine_key']] = $candidate['available_to_claim'];
                        $total += $available;
                    }
                }
                if (!$items) {
                    throw new DomainException('No fines are available for payment to this organization.', 409);
                }
                if ($xenditReference !== null) $amount = $total;
                if ($amount !== $total) {
                    throw new DomainException('The payable total changed. Refresh and submit the updated total.', 409);
                }
                $fine = [
                    'org_user_id' => $orgId
                ];
                $key = 'all-' . bin2hex(random_bytes(16));
            }
            if ($method === 'GCASH' && $this->db->table('payment_claims')
                ->select('id')
                ->where('method', '=', 'GCASH')
                ->where('reference_number', '=', $reference)
                ->in('status', [
                    'PENDING',
                    'CONFIRMED'
                ])
                ->get(PDO::FETCH_COLUMN)) {
                throw new DomainException('This reference already has a pending or confirmed claim.', 409);
            }
            // The generated unique reference index rejects duplicate GCash claims atomically.
            $insert = $this->db->raw("INSERT IGNORE INTO payment_claims
                (student_id,org_user_id,fine_key,amount,method,reference_number,proof_file,proof_mime,submitted_at,status)
                VALUES (?,?,?,?,?,?,?,?,?,'PENDING')", [
                $student['id'],
                $fine['org_user_id'],
                $key,
                $this->decimal($amount),
                $method,
                $method === 'GCASH' ? $reference : null,
                $proof['file'] ?? null,
                $proof['mime'] ?? null,
                $this->now()
            ]);
            if ($insert->rowCount() !== 1) {
                throw new DomainException('This reference already has a pending or confirmed claim.', 409);
            }
            $claimId = (int) $this->db->last_id();
            foreach ($items as $fineKey => $allocation) {
                $this->db->table('payment_claim_items')
                    ->insert([
                        'claim_id' => $claimId,
                        'fine_key' => $fineKey,
                        'amount' => $allocation,
                    ]);
            }
            if ($xenditReference !== null) {
                $this->db->table('xendit_payments')->insert([
                    'claim_id' => $claimId,
                    'reference_id' => $xenditReference,
                    'state' => 'CREATING',
                    'mode' => 'test',
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
            }
            $this->db->commit();
            return $claimId;
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    public function cancel(int $userId, int $claimId): void
    {
        $this->db->transaction();
        try {
            $s = $this->student($userId, true);
            $claim = $this->db->raw('SELECT id, status, method FROM payment_claims WHERE id=? AND student_id=? FOR UPDATE', [
                $claimId,
                $s['id']
            ])->fetch(PDO::FETCH_ASSOC);
            if (!$claim) {
                throw new DomainException('Claim not found.', 404);
            }
            if ($claim['method'] === 'XENDIT_TEST') {
                throw new DomainException('Xendit checkouts are managed by the payment provider. Check their status or wait for expiry.', 409);
            }
            if ($claim['status'] !== 'PENDING') {
                throw new DomainException('Only pending claims can be cancelled.', 409);
            }
            $this->db->table('payment_claims')
                ->where('id', '=', $claimId)
                ->update([
                    'status' => 'CANCELLED',
                    'reviewed_at' => $this->now()
                ]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    private function org(int $id): array
    {
        $this->call_org_model();
        return $this->orgModel->organization($id);
    }

    private $orgModel;

    private function call_org_model(): void
    {
        if (!$this->orgModel) {
            $this->orgModel = $this->call->model('OrgEventModel');
        }
    }

    private function org_scope(array $org, string $course): bool
    {
        return $org['allowed_courses'] === null || in_array(strtoupper(trim($course)), $org['allowed_courses'], true);
    }

    public function organization_claims(int $orgId): array
    {
        $org = $this->org($orgId);
        $rows = $this->claims('p.org_user_id', $orgId);
        return array_values(array_filter($rows, function ($row) use ($org) {
            $s = $this->db->table('students')
                ->select('course')
                ->where('id', '=', $row['student_id'])
                ->get(PDO::FETCH_COLUMN);
            return $this->org_scope($org, (string) $s);
        }));
    }

    public function organization_fines(int $orgId, array $filters = [], bool $export = false): array
    {
        $org = $this->org($orgId);
        $search = $filters['search'] ?? '';
        $status = $filters['status'] ?? '';
        if (!is_string($search) || mb_strlen($search) > 100 || !is_string($status) || !in_array($status, [
            '',
            'UNPAID',
            'PAID'
        ], true)) {
            throw new DomainException('Invalid fine filters.', 422);
        }
        $page = filter_var($filters['page'] ?? 1, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1
            ]
        ]);
        if (!$page) {
            throw new DomainException('Invalid page.', 422);
        }
        $sql = "SELECT s.id student_id,s.student_number,s.full_name,s.course,s.year_level,s.section,f.*,
            COALESCE(p.paid_amount,0) paid_amount,COALESCE(p.pending_amount,0) pending_amount
            FROM (SELECT CONCAT('scan-',r.id) fine_key,r.student_id fine_student_id,e.org_user_id,
                CASE WHEN r.record_type='ATTENDANCE' THEN 'Attendance fine' ELSE COALESCE(v.name,'Violation') END violation_name,e.name event_name,r.fine_amount,r.recorded_at,r.record_type
                FROM org_scan_records r JOIN org_events e ON e.id=r.event_id
                LEFT JOIN org_violation_types v ON v.id=e.violation_type_id WHERE (r.record_type='VIOLATION' OR r.fine_amount>0) AND e.org_user_id=?
                UNION ALL SELECT CONCAT('violation-',r.id),r.student_id,r.org_user_id,r.violation_name,
                COALESCE(e.name,'Standalone violation'),r.fine_amount,r.recorded_at,'VIOLATION' FROM org_violation_records r
                LEFT JOIN org_events e ON e.id=r.event_id WHERE r.org_user_id=?) f
            JOIN students s ON s.id=f.fine_student_id
            LEFT JOIN (SELECT fine_key,student_id,SUM(CASE WHEN status='CONFIRMED' THEN amount ELSE 0 END) paid_amount,
                SUM(CASE WHEN status='PENDING' THEN amount ELSE 0 END) pending_amount
                FROM (" . $this->allocated_payments() . ") allocated WHERE org_user_id=? GROUP BY fine_key,student_id) p
                ON p.fine_key=f.fine_key AND p.student_id=s.id
            ORDER BY s.course,s.year_level,s.section,s.full_name,s.student_number,f.recorded_at DESC,f.fine_key";
        $rows = $this->db->raw($sql, [
            $orgId,
            $orgId,
            $orgId
        ])->fetchAll(PDO::FETCH_ASSOC);
        $rows = $this->call->model('PaymentPeriodModel')->apply($rows);
        $students = [];
        foreach ($rows as $row) {
            if (!$this->org_scope($org, $row['course'])) {
                continue;
            }
            if (trim($search) !== '' && mb_stripos(implode(' ', [
                $row['student_number'],
                $row['full_name'],
                $row['course'],
                $row['year_level'],
                $row['section']
            ]), trim($search)) === false) {
                continue;
            }
            $id = $row['student_id'];
            if (!isset($students[$id])) {
                $students[$id] = array_intersect_key($row, array_flip([
                    'student_id',
                    'student_number',
                    'full_name',
                    'course',
                    'year_level',
                    'section'
                ])) + ['total' => 0, 'paid' => 0, 'pending' => 0, 'balance' => 0, 'adjustment' => 0, 'discount' => 0, 'fines' => []];
            }
            $total = $this->cents((string) $row['fine_amount']);
            $paid = $this->cents((string) $row['paid_amount']);
            $balance = $this->cents((string) $row['outstanding']);
            $students[$id]['adjustment'] += $this->cents((string) $row['cap_adjustment']);
            $students[$id]['discount'] += $this->cents((string) $row['discount_amount']);
            $students[$id]['total'] += $total;
            $students[$id]['paid'] += $paid;
            $students[$id]['pending'] += $this->cents((string) $row['pending_amount']);
            $students[$id]['balance'] += $balance;
            $students[$id]['fines'][] = array_intersect_key($row, array_flip([
                'fine_key',
                'violation_name',
                'event_name',
                'fine_amount',
                'paid_amount',
                'pending_amount',
                'cap_adjustment',
                'discount_amount',
                'payable_amount',
                'semester_label',
                'recorded_at',
                'record_type'
            ])) + ['outstanding' => $this->decimal($balance)];
        }
        $data = [];
        $totals = [
            'total_fine' => 0,
            'cap_adjustment' => 0,
            'discount_amount' => 0,
            'paid_amount' => 0,
            'pending_amount' => 0,
            'outstanding' => 0
        ];
        foreach ($students as $student) {
            $student['payment_status'] = $student['balance'] === 0 ? 'PAID' : 'UNPAID';
            if ($status !== '' && $student['payment_status'] !== $status) {
                continue;
            }
            foreach ([
                'total_fine' => 'total',
                'cap_adjustment' => 'adjustment',
                'discount_amount' => 'discount',
                'paid_amount' => 'paid',
                'pending_amount' => 'pending',
                'outstanding' => 'balance'
            ] as $key => $value) {
                $totals[$key] += $student[$value];
                $student[$key] = $this->decimal($student[$value]);
                unset($student[$value]);
            }
            $student['violation_count'] = count(array_filter($student['fines'], fn ($fine) => $fine['record_type'] === 'VIOLATION'));
            $data[] = $student;
        }
        foreach ($totals as & $amount) {
            $amount = $this->decimal($amount);
        }
        unset($amount);
        $count = count($data);
        $pages = max(1, (int) ceil($count / 25));
        $page = min($page, $pages);
        return [
            'data' => $export ? $data : array_slice($data, ($page - 1) * 25, 25),
            'summary' => $totals + ['student_count' => $count],
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $pages,
                'total_records' => $count
            ],
            'columns' => [
                'course' => 'Course',
                'year_level' => 'Year Level',
                'section' => 'Section',
                'full_name' => 'Name',
                'student_number' => 'Student Number',
                'violation_count' => 'Violations',
                'total_fine' => 'Total Fines (PHP)',
                'cap_adjustment' => 'Cap Reduction (PHP)',
                'discount_amount' => 'Voucher / Discount (PHP)',
                'paid_amount' => 'Confirmed Payments (PHP)',
                'pending_amount' => 'Pending Claims (PHP)',
                'outstanding' => 'Outstanding (PHP)',
                'payment_status' => 'Payment Status'
            ]
        ];
    }

    public function review(int $orgId, int $claimId, string $decision, string $note): void
    {
        if (!in_array($decision, [
            'CONFIRMED',
            'REJECTED'
        ], true) || mb_strlen($note) > 500) {
            throw new DomainException('Invalid payment review.', 422);
        }
        if ($decision === 'REJECTED' && trim($note) === '') {
            throw new DomainException('Enter a reason for rejection.', 422);
        }
        $org = $this->org($orgId);
        $this->db->transaction();
        try {
            $this->db->raw('SELECT id FROM users WHERE id=? FOR UPDATE', [$orgId]);
            $initial = $this->db->table('payment_claims')
                ->select('student_id')
                ->where('id', '=', $claimId)
                ->where('org_user_id', '=', $orgId)
                ->get(PDO::FETCH_COLUMN);
            if (!$initial) {
                throw new DomainException('Claim not found.', 404);
            }
            $student = $this->db->raw('SELECT id, course FROM students WHERE id=? FOR UPDATE', [
                $initial
            ])->fetch(PDO::FETCH_ASSOC);
            if (!$this->org_scope($org, $student['course'])) {
                throw new DomainException('Student is outside your course.', 403);
            }
            $claim = $this->db->raw('SELECT id, student_id, org_user_id, fine_key, amount, status, method FROM payment_claims WHERE id=? AND org_user_id=? FOR UPDATE', [
                $claimId,
                $orgId
            ])->fetch(PDO::FETCH_ASSOC);
            if ($claim['method'] === 'XENDIT_TEST') {
                throw new DomainException('Xendit test payments can only be confirmed by verified provider status.', 409);
            }
            if ($claim['status'] !== 'PENDING') {
                throw new DomainException('This claim has already been reviewed or cancelled.', 409);
            }
            $items = $this->db->table('payment_claim_items')
                ->select('fine_key,amount')
                ->where('claim_id', '=', $claimId)
                ->get_all(PDO::FETCH_ASSOC);
            if (!$items) {
                $items = [
                    [
                        'fine_key' => $claim['fine_key'],
                        'amount' => $claim['amount']
                    ]
                ];
            }
            $allocated = 0;
            foreach ($items as $item) {
                $fine = $this->own_fine((int) $student['id'], $item['fine_key']);
                $allocated += $this->cents($item['amount']);
                if ((int) $fine['org_user_id'] !== $orgId) {
                    throw new DomainException('Invalid payment allocation.', 409);
                }
                if ($decision === 'CONFIRMED' && $this->cents($item['amount']) > $this->cents($fine['outstanding'])) {
                    throw new DomainException('Payment exceeds an outstanding fine.', 409);
                }
            }
            if ($allocated !== $this->cents($claim['amount'])) {
                throw new DomainException('Payment allocation total does not match claim.', 409);
            }
            $receipt = $decision === 'CONFIRMED' ? 'FMS-' . date('Y') . '-' . str_pad((string) $claimId, 10, '0', STR_PAD_LEFT) : null;
            $this->db->table('payment_claims')
                ->where('id', '=', $claimId)
                ->update([
                    'status' => $decision,
                    'reviewed_at' => $this->now(),
                    'reviewed_by' => $orgId,
                    'review_note' => trim($note),
                    'receipt_number' => $receipt
                ]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    public function document(int $userId, string $role, int $claimId, bool $receipt = false): array
    {
        $claim = $this->db->table('payment_claims p')
            ->select('p.id,p.student_id,p.org_user_id,p.fine_key,p.amount,p.method,p.reference_number,p.status,p.submitted_at,p.reviewed_at,p.reviewed_by,p.review_note,p.receipt_number,p.proof_file,p.proof_mime,s.full_name,s.student_number,s.course,u.organization_name')
            ->join('students s', 's.id=p.student_id')
            ->join('users u', 'u.id=p.org_user_id')
            ->where('p.id', $claimId)
            ->get();
        if (!$claim) {
            throw new DomainException('Claim not found.', 404);
        }
        if ($role === 'STUDENT') {
            $s = $this->student($userId);
            if ((int) $s['id'] !== (int) $claim['student_id']) {
                throw new DomainException('Claim not found.', 404);
            }
        } elseif ($role === 'ORGANIZATION') {
            if ((int) $claim['org_user_id'] !== $userId || !$this->org_scope($this->org($userId), $claim['course'])) {
                throw new DomainException('Claim not found.', 404);
            }
        } else {
            throw new DomainException('Access denied.', 403);
        }
        if ($receipt && $claim['status'] !== 'CONFIRMED') {
            throw new DomainException('Receipts are available only for confirmed payments.', 409);
        }
        $count = (int) $this->db->table('payment_claim_items')
            ->where('claim_id', '=', $claimId)
            ->count();
        if ($count) {
            $claim['fine_key'] = 'All fines (' . $count . ' items)';
        }
        return $claim;
    }
}
