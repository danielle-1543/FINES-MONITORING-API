<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
class FineArchiveModel extends Model
{
    public function report(array $auth, array $filters): array
    {
        $role = $auth['role'];
        if ($role === 'STUDENT') return $this->student_current((int) $auth['sub']);
        $orgId = $role === 'ORGANIZATION' ? (int) $auth['sub'] : 0;
        $studentId = 0;
        if (!empty($filters['student_id'])) {
            $studentId = filter_var($filters['student_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$studentId) throw new DomainException('Choose a valid student.', 422);
        }
        $where = []; $params = [];
        if ($orgId) { $where[] = 'org_user_id=?'; $params[] = $orgId; }
        if ($studentId) { $where[] = 'student_id=?'; $params[] = $studentId; }
        // Historical ownership follows the original organization, not a student's
        // current course after shifting. Student access is always their own ID.
        $sql = "SELECT student_id FROM (SELECT r.student_id,e.org_user_id FROM org_scan_records r JOIN org_events e ON e.id=r.event_id
            UNION ALL SELECT student_id,org_user_id FROM org_violation_records) sources";
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $ids = $this->db->raw('SELECT DISTINCT student_id FROM (' . $sql . ') selected_students LIMIT 2001', $params)->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) > 2000) throw new DomainException('Choose a student to narrow this report to 2,000 students or fewer.', 422);
        $periods = $this->call->model('AcademicPeriodModel')->periods();
        $periodMap = array_column($periods, null, 'id');
        $selected = $filters['academic_period_id'] ?? 'all';
        if ($selected !== 'all' && (!ctype_digit((string) $selected) || ((int) $selected && !isset($periodMap[(int) $selected])))) throw new DomainException('Choose a valid academic semester.', 422);
        $fines = $ids ? $this->call->model('PaymentModel')->fine_rows($ids) : [];
        $students = $ids ? $this->db->table('students')->select('id,student_number,full_name')->in('id', $ids)->get_all() : [];
        $students = array_column($students, null, 'id');
        $byKey = []; $summaries = []; $visible = [];
        foreach ($fines as $fine) {
            if ($orgId && (int) $fine['org_user_id'] !== $orgId) continue;
            $periodId = (int) ($fine['academic_period_id'] ?? 0);
            $fine['academic_period_id'] = $periodId;
            $fine['academic_period_label'] = $periodMap[$periodId]['label'] ?? 'Legacy / Unassigned';
            $fine['full_name'] = $students[$fine['student_id']]['full_name'] ?? '';
            $fine['student_number'] = $students[$fine['student_id']]['student_number'] ?? '';
            $paid = (int) round((float) $fine['paid_amount'] * 100);
            $due = (int) round((float) $fine['outstanding'] * 100);
            $fine['settlement_status'] = $due === 0 ? ($paid > 0 ? 'PAID' : 'SETTLED') : ($paid > 0 ? 'PARTIALLY_PAID' : 'UNPAID');
            $key = $periodId . ':' . $fine['org_user_id'];
            if (!isset($summaries[$key])) $summaries[$key] = ['academic_period_id' => $periodId, 'academic_period_label' => $fine['academic_period_label'],
                'org_user_id' => $fine['org_user_id'], 'organization_name' => $fine['organization_name'], 'count' => 0,
                'original' => 0, 'adjusted' => 0, 'paid' => 0, 'pending' => 0, 'outstanding' => 0];
            $summaries[$key]['count']++;
            foreach (['original' => 'fine_amount', 'adjusted' => 'payable_amount', 'paid' => 'paid_amount', 'pending' => 'pending_amount', 'outstanding' => 'outstanding'] as $target => $source) {
                $summaries[$key][$target] += (int) round((float) $fine[$source] * 100);
            }
            $byKey[$fine['fine_key']] = $fine;
            if ($selected === 'all' || $periodId === (int) $selected) $visible[] = $fine;
        }
        foreach ($summaries as &$summary) foreach (['original', 'adjusted', 'paid', 'pending', 'outstanding'] as $key) $summary[$key] = number_format($summary[$key] / 100, 2, '.', '');
        unset($summary);
        $payments = [];
        if ($ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $paymentRows = $this->db->raw("SELECT p.id,p.student_id,p.org_user_id,p.status,p.method,p.amount,p.reference_number,p.receipt_number,p.submitted_at,p.reviewed_at,
                COALESCE(i.fine_key,p.fine_key) fine_key,COALESCE(i.amount,p.amount) allocated_amount
                FROM payment_claims p LEFT JOIN payment_claim_items i ON i.claim_id=p.id
                WHERE p.student_id IN ($marks)" . ($orgId ? ' AND p.org_user_id=?' : '') . ' ORDER BY p.id DESC', $orgId ? array_merge($ids, [$orgId]) : $ids)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($paymentRows as $payment) {
                $fine = $byKey[$payment['fine_key']] ?? null;
                if (!$fine || ($selected !== 'all' && $fine['academic_period_id'] !== (int) $selected)) continue;
                $payments[] = $payment + ['academic_period_label' => $fine['academic_period_label'], 'organization_name' => $fine['organization_name'],
                    'full_name' => $fine['full_name'], 'student_number' => $fine['student_number']];
            }
        }
        $status = $filters['status'] ?? 'ALL';
        if (!in_array($status, ['ALL', 'PAID', 'UNPAID', 'PARTIALLY_PAID', 'SETTLED'], true)) throw new DomainException('Choose a valid fine status.', 422);
        if ($status !== 'ALL') $visible = array_values(array_filter($visible, fn ($fine) => $fine['settlement_status'] === $status));
        $export = ($filters['export'] ?? '') === '1';
        if ($export && count($visible) > 10000) throw new DomainException('Narrow the export to 10,000 fines or fewer.', 422);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pages = max(1, (int) ceil(count($visible) / 20)); $page = min($page, $pages);
        $paymentPage = max(1, (int) ($filters['payment_page'] ?? 1));
        $paymentPages = max(1, (int) ceil(count($payments) / 20)); $paymentPage = min($paymentPage, $paymentPages);
        return ['periods' => $periods, 'summaries' => array_values($summaries), 'fines' => $export ? $visible : array_slice($visible, ($page - 1) * 20, 20),
            'payments' => $export ? $payments : array_slice($payments, ($paymentPage - 1) * 20, 20),
            'pagination' => ['page' => $page, 'pages' => $pages, 'total' => count($visible)],
            'payment_pagination' => ['page' => $paymentPage, 'pages' => $paymentPages, 'total' => count($payments)]];
    }

    private function student_current(int $userId): array
    {
        $studentId = (int) $this->db->table('students')->select('id')->where('user_id', $userId)->get(PDO::FETCH_COLUMN);
        if (!$studentId) throw new DomainException('Student not found.', 404);
        $current = $this->call->model('AcademicPeriodModel')->current();
        $fields = ['original', 'cap_adjustment', 'discount', 'payable', 'paid', 'outstanding'];
        $totals = array_fill_keys($fields, 0);
        $decimal = static fn ($amount) => number_format($amount / 100, 2, '.', '');
        $empty = ['current_period' => null, 'records' => [], 'organizations' => [], 'total_violations' => 0, 'summary' => array_map($decimal, $totals)];
        if (!$current || $current['status'] !== 'OPEN') return $empty;
        $periodId = (int) $current['id'];
        $records = []; $organizations = []; $violationCount = 0;
        $addOrganization = static function ($id, $name) use (&$organizations, $totals): void {
            if (!isset($organizations[$id])) $organizations[$id] = ['id' => (int) $id, 'name' => $name] + $totals;
        };
        // Include uncharged absences too; the monetary ledger intentionally omits zero attendance fines.
        $absences = $this->db->table('org_scan_records r')
            ->select('r.id,r.recorded_at,r.fine_amount,e.name AS event_name,e.org_user_id,u.organization_name')
            ->join('org_events e', 'e.id=r.event_id')->join('users u', 'u.id=e.org_user_id')
            ->where('r.student_id', $studentId)->where('r.academic_period_id', $periodId)
            ->where('r.record_type', 'ATTENDANCE')->where('r.attendance_status', 'ABSENT')->get_all();
        foreach ($absences as $absence) {
            $key = 'scan-' . $absence['id'];
            $records[$key] = ['id' => $key, 'description' => 'Absence · ' . $absence['event_name'], 'recorded_at' => $absence['recorded_at'],
                'organization_id' => (int) $absence['org_user_id'], 'organization_name' => $absence['organization_name'],
                'fine_amount' => $absence['fine_amount'], 'type' => 'Absence'];
            $addOrganization($absence['org_user_id'], $absence['organization_name']);
        }
        // Reuse the complete personal ledger before filtering: legacy cap coverage and
        // confirmed allocations must not be recalculated from a partial semester subset.
        foreach ($this->call->model('PaymentModel')->fine_rows([$studentId]) as $fine) {
            if ((int) ($fine['academic_period_id'] ?? 0) !== $periodId) continue;
            if ($fine['record_type'] === 'VIOLATION') $violationCount++;
            $key = $fine['fine_key'];
            if (!isset($records[$key])) $records[$key] = ['id' => $key,
                'description' => ($fine['violation_name'] ?: 'Fine') . ' · ' . $fine['event_name'], 'recorded_at' => $fine['recorded_at'],
                'organization_id' => (int) $fine['org_user_id'], 'organization_name' => $fine['organization_name'],
                'fine_amount' => $fine['fine_amount'], 'type' => 'Fine'];
            $addOrganization($fine['org_user_id'], $fine['organization_name']);
            foreach (['original' => 'fine_amount', 'cap_adjustment' => 'cap_adjustment', 'discount' => 'discount_amount',
                'payable' => 'payable_amount', 'paid' => 'paid_amount', 'outstanding' => 'outstanding'] as $target => $source) {
                $amount = (int) round((float) $fine[$source] * 100);
                $totals[$target] += $amount;
                $organizations[$fine['org_user_id']][$target] += $amount;
            }
        }
        $records = array_values($records);
        usort($records, static fn ($a, $b) => [$b['recorded_at'], $b['id']] <=> [$a['recorded_at'], $a['id']]);
        foreach ($organizations as &$organization) foreach ($fields as $field) $organization[$field] = $decimal($organization[$field]);
        unset($organization);
        $organizations = array_values($organizations);
        usort($organizations, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return ['current_period' => ['id' => $periodId, 'label' => $current['label']], 'records' => $records,
            'organizations' => $organizations, 'total_violations' => $violationCount, 'summary' => array_map($decimal, $totals)];
    }

    public function dashboard_statistics(int $userId): array
    {
        // Payments follow their original fine semester, not an inferred calendar date.
        // The personal ledger includes only confirmed allocations in its paid amount.
        $archive = $this->student_current($userId);
        return ['current_period' => $archive['current_period'], 'summary' => [
            'total_violations' => $archive['total_violations'],
            'total_fines' => $archive['summary']['original'],
            'paid_amount' => $archive['summary']['paid'],
            'unpaid_fines' => $archive['summary']['outstanding'],
        ]];
    }
}
