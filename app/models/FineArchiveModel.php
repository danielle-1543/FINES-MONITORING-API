<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
class FineArchiveModel extends Model
{
    public function report(array $auth, array $filters): array
    {
        $role = $auth['role'];
        $orgId = $role === 'ORGANIZATION' ? (int) $auth['sub'] : 0;
        $studentId = 0;
        if ($role === 'STUDENT') {
            $studentId = (int) $this->db->table('students')->select('id')->where('user_id', '=', $auth['sub'])->get(PDO::FETCH_COLUMN);
            if (!$studentId) throw new DomainException('Student not found.', 404);
        } elseif (!empty($filters['student_id'])) {
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
}
