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
        $search = $filters['search'] ?? '';
        if (!is_string($search) || mb_strlen($search) > 150) throw new DomainException('Enter a student number, name or section of up to 150 characters.', 422);
        $search = trim($search);
        $where = []; $params = [];
        if ($orgId) { $where[] = 'sources.org_user_id=?'; $params[] = $orgId; }
        if ($studentId) { $where[] = 'sources.student_id=?'; $params[] = $studentId; }
        // Historical ownership follows the original organization, not a student's
        // current course after shifting. Student access is always their own ID.
        $sql = "SELECT sources.student_id FROM (SELECT r.student_id,e.org_user_id FROM org_scan_records r JOIN org_events e ON e.id=r.event_id
            UNION ALL SELECT student_id,org_user_id FROM org_violation_records) sources";
        if ($search !== '') {
            $sql .= ' JOIN students search_student ON search_student.id=sources.student_id';
            $where[] = "(LOWER(search_student.student_number) LIKE LOWER(?) ESCAPE '!' OR LOWER(search_student.full_name) LIKE LOWER(?) ESCAPE '!' OR LOWER(search_student.section) LIKE LOWER(?) ESCAPE '!')";
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            array_push($params, $pattern, $pattern, $pattern);
        }
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $ids = $this->db->raw('SELECT DISTINCT student_id FROM (' . $sql . ') selected_students LIMIT 2001', $params)->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) > 2000) throw new DomainException('Search by student number, name or section to narrow this report to 2,000 students or fewer.', 422);
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
        // Student-level payment summaries are separate from the allocation audit.
        // Aggregate from the complete ledger once, not once per claim/item join.
        $studentTotals = [];
        foreach ($visible as $fine) {
            $key = $fine['student_id'] . ':' . $fine['org_user_id'] . ':' . $fine['academic_period_id'];
            if (!isset($studentTotals[$key])) $studentTotals[$key] = ['total_fine' => 0, 'paid_amount' => 0, 'outstanding' => 0];
            foreach (['total_fine' => 'payable_amount', 'paid_amount' => 'paid_amount', 'outstanding' => 'outstanding'] as $target => $source) {
                $studentTotals[$key][$target] += (int) round((float) $fine[$source] * 100);
            }
        }
        $studentPayments = [];
        foreach ($payments as $payment) {
            $fine = $byKey[$payment['fine_key']];
            $key = $payment['student_id'] . ':' . $payment['org_user_id'] . ':' . $fine['academic_period_id'];
            if (!isset($studentPayments[$key])) $studentPayments[$key] = [
                'id' => $key, 'academic_period_label' => $fine['academic_period_label'],
                'academic_period_id' => $fine['academic_period_id'], 'organization_name' => $fine['organization_name'],
                'student_number' => $fine['student_number'], 'full_name' => $fine['full_name'],
                'methods' => [], 'references' => [], 'claim_statuses' => [], 'submitted_at' => null, 'reviewed_at' => null,
            ];
            $row = &$studentPayments[$key];
            $row['methods'][$payment['method']] = true;
            $reference = $payment['reference_number'] ?: ($payment['receipt_number'] ?: '—');
            $row['references'][$reference] = true;
            $row['claim_statuses'][$payment['status']] = true;
            if ($payment['submitted_at'] > ($row['submitted_at'] ?? '')) $row['submitted_at'] = $payment['submitted_at'];
            if ($payment['status'] === 'CONFIRMED' && $payment['reviewed_at'] > ($row['reviewed_at'] ?? '')) $row['reviewed_at'] = $payment['reviewed_at'];
            unset($row);
        }
        foreach ($studentPayments as $key => &$row) {
            $totals = $studentTotals[$key];
            $row['status'] = $totals['outstanding'] === 0 ? ($totals['paid_amount'] > 0 ? 'PAID' : 'SETTLED')
                : ($totals['paid_amount'] > 0 ? 'PARTIALLY_PAID' : (isset($row['claim_statuses']['PENDING']) ? 'PENDING'
                    : (count($row['claim_statuses']) === 1 && isset($row['claim_statuses']['CANCELLED']) ? 'CANCELLED' : 'UNPAID')));
            $row['method'] = implode(' · ', array_keys($row['methods']));
            $row['reference_number'] = implode(' · ', array_keys($row['references']));
            foreach (['total_fine', 'paid_amount'] as $field) $row[$field] = number_format($totals[$field] / 100, 2, '.', '');
            unset($row['methods'], $row['references'], $row['claim_statuses']);
        }
        unset($row);
        $studentPayments = array_values($studentPayments);
        $status = $filters['status'] ?? 'ALL';
        if (!in_array($status, ['ALL', 'PAID', 'UNPAID', 'PARTIALLY_PAID', 'SETTLED'], true)) throw new DomainException('Choose a valid fine status.', 422);
        if ($status !== 'ALL') $visible = array_values(array_filter($visible, fn ($fine) => $fine['settlement_status'] === $status));
        $export = ($filters['export'] ?? '') === '1';
        if ($export && count($visible) > 10000) throw new DomainException('Narrow the export to 10,000 fines or fewer.', 422);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pages = max(1, (int) ceil(count($visible) / 10)); $page = min($page, $pages);
        $paymentPage = max(1, (int) ($filters['payment_page'] ?? 1));
        $studentPaymentPages = max(1, (int) ceil(count($studentPayments) / 10));
        $studentPaymentPage = min($paymentPage, $studentPaymentPages);
        $paymentPages = max(1, (int) ceil(count($payments) / 10)); $paymentPage = min($paymentPage, $paymentPages);
        return ['periods' => $periods, 'summaries' => array_values($summaries), 'fines' => $export ? $visible : array_slice($visible, ($page - 1) * 10, 10),
            'student_payments' => $export ? $studentPayments : array_slice($studentPayments, ($studentPaymentPage - 1) * 10, 10),
            'student_payment_pagination' => ['page' => $studentPaymentPage, 'pages' => $studentPaymentPages, 'total' => count($studentPayments)],
            'payments' => $export ? $payments : array_slice($payments, ($paymentPage - 1) * 10, 10),
            'pagination' => ['page' => $page, 'pages' => $pages, 'total' => count($visible)],
            'payment_pagination' => ['page' => $paymentPage, 'pages' => $paymentPages, 'total' => count($payments)]];
    }

    public function semester_student_export(array $auth, array $filters): array
    {
        if (!in_array($auth['role'], ['ADMIN', 'ORGANIZATION'], true)) throw new DomainException('Organization or administrator access required.', 403);
        $periodId = filter_var($filters['academic_period_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $orgId = filter_var($filters['org_user_id'] ?? $auth['sub'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($periodId === false || !$orgId) throw new DomainException('Choose an organization and academic semester to export.', 422);
        if ($auth['role'] === 'ORGANIZATION' && $orgId !== (int) $auth['sub']) throw new DomainException('You may export only your organization’s records.', 403);
        // Reuse the complete ledger, including existing cap/discount/payment rules.
        $report = $this->report(['role' => 'ORGANIZATION', 'sub' => $orgId], ['academic_period_id' => (string) $periodId, 'export' => '1', 'search' => $filters['search'] ?? '']);
        $ids = array_values(array_unique(array_column($report['fines'], 'student_id')));
        $profiles = $ids ? $this->db->table('students')->select('id,student_number,full_name,course,year_level,section')->in('id', $ids)->get_all() : [];
        $profiles = array_column($profiles, null, 'id');
        $current = $this->call->model('AcademicPeriodModel')->current();
        if (!$current || (int) $current['id'] !== $periodId) {
            $saved = [];
            $snapshots = $this->db->table('academic_record_changes')->select('change_details')
                ->in('source_kind', ['SEMESTER_CHANGE', 'SEMESTER_DELETED'])->where('source_id', $periodId)->order_by('id', 'DESC')->get_all();
            foreach ($snapshots as $entry) {
                $snapshot = json_decode($entry['change_details'], true, 512, JSON_THROW_ON_ERROR);
                foreach ($snapshot['semester_students'] ?? $snapshot['students'] ?? [] as $student) {
                    if ((int) ($student['current_academic_period_id'] ?? 0) === $periodId && isset($profiles[$student['id']]) && !isset($saved[$student['id']])) $saved[$student['id']] = $student;
                }
            }
            if ($ids) foreach ($this->db->table('student_change_history')->select('student_id,before_values,after_values')->in('student_id', $ids)->order_by('id', 'DESC')->get_all() as $entry) {
                foreach (['after_values', 'before_values'] as $field) {
                    $state = json_decode($entry[$field], true, 512, JSON_THROW_ON_ERROR);
                    if ((int) ($state['current_academic_period_id'] ?? 0) === $periodId && !isset($saved[$entry['student_id']])) $saved[$entry['student_id']] = $state;
                }
            }
            foreach ($profiles as $id => &$profile) {
                // Never present today's course/year/section as an unknown historic profile.
                $profile = array_replace($profile, ['course' => 'Not recorded', 'year_level' => 'Not recorded', 'section' => 'Not recorded'], $saved[$id] ?? []);
            }
            unset($profile);
        }
        $rows = []; $fineStudents = [];
        foreach ($report['fines'] as $fine) {
            $id = $fine['student_id']; $fineStudents[$fine['fine_key']] = $id;
            if (!isset($rows[$id])) {
                $profile = $profiles[$id] ?? [];
                $rows[$id] = ['academic_period_label' => $fine['academic_period_label'], 'course' => $profile['course'] ?? '',
                    'year_level' => in_array($profile['year_level'] ?? '', ['Graduated', 'Graduating'], true) ? '4th Year' : ($profile['year_level'] ?? ''),
                    'section' => $profile['section'] ?? '', 'full_name' => $profile['full_name'] ?? $fine['full_name'],
                    'student_number' => $profile['student_number'] ?? $fine['student_number'],
                    'violation_fines' => 0, 'absence_fines' => 0, 'total_fine' => 0, 'outstanding' => 0, '_paid' => 0, 'paid_at' => ''];
            }
            $amount = (int) round((float) $fine['payable_amount'] * 100);
            $rows[$id][$fine['record_type'] === 'VIOLATION' ? 'violation_fines' : 'absence_fines'] += $amount;
            $rows[$id]['total_fine'] += $amount;
            $rows[$id]['outstanding'] += (int) round((float) $fine['outstanding'] * 100);
            $rows[$id]['_paid'] += (int) round((float) $fine['paid_amount'] * 100);
        }
        foreach ($report['payments'] as $payment) {
            $id = $fineStudents[$payment['fine_key']] ?? null;
            if ($id !== null && $payment['status'] === 'CONFIRMED' && !empty($payment['reviewed_at']) && $payment['reviewed_at'] > $rows[$id]['paid_at']) $rows[$id]['paid_at'] = $payment['reviewed_at'];
        }
        foreach ($rows as &$row) {
            $row['status'] = $row['outstanding'] === 0 ? ($row['_paid'] > 0 ? 'PAID' : 'SETTLED') : ($row['_paid'] > 0 ? 'PARTIALLY_PAID' : 'UNPAID');
            if ($row['status'] !== 'PAID') $row['paid_at'] = '';
            foreach (['violation_fines', 'absence_fines', 'total_fine', 'outstanding'] as $field) $row[$field] = number_format($row[$field] / 100, 2, '.', '');
            unset($row['_paid']);
        }
        unset($row);
        $rows = array_values($rows);
        usort($rows, static fn ($a, $b) => [$a['full_name'], $a['student_number']] <=> [$b['full_name'], $b['student_number']]);
        return ['data' => $rows, 'columns' => ['academic_period_label' => 'Academic Year / Semester', 'course' => 'Course', 'year_level' => 'Year Level',
            'section' => 'Section', 'full_name' => 'Full Name', 'student_number' => 'Student Number', 'violation_fines' => 'Violation Fines (PHP)',
            'absence_fines' => 'Absence Fines (PHP)', 'total_fine' => 'Total Fines (PHP)', 'status' => 'Status', 'outstanding' => 'Outstanding Balance (PHP)', 'paid_at' => 'Paid At']];
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
