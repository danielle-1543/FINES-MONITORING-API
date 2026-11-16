<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class OrganizationEmailModel extends Model
{
    private function mailer(): BrevoMailer
    {
        if (!class_exists('BrevoMailer', false)) require_once dirname(__DIR__) . '/libraries/BrevoMailer.php';
        return new BrevoMailer();
    }

    private function schema_ready(): bool
    {
        $columns = $this->db->raw('SELECT * FROM users LIMIT 0');
        for ($index = 0; $index < $columns->columnCount(); $index++) {
            $column = $columns->getColumnMeta($index);
            if (($column['name'] ?? '') === 'organization_email_sender_code') return true;
        }
        return false;
    }

    private function require_schema(): void
    {
        if (!$this->schema_ready()) {
            throw new DomainException('Organization email senders are not set up yet. Apply the organization_email_senders database migration first.', 503);
        }
    }

    private function configured(string $code): array
    {
        $senders = BrevoMailer::configured_senders();
        if (!isset($senders[$code])) throw new DomainException('Choose an assigned system sender.', 422);
        return $senders[$code] + ['code' => $code];
    }

    public function sender_statuses(): array
    {
        $this->require_schema();
        return ['setup_ready' => true, 'senders' => array_values($this->mailer()->sender_statuses())];
    }

    public function organization_sender(int $orgId): array
    {
        $this->require_schema();
        $org = $this->db->table('users')->select('id,organization_name,organization_email_sender_code')->where('id', $orgId)->where('role', 'ORGANIZATION')->get();
        if (!$org) throw new DomainException('Organization not found.', 404);
        $code = trim((string) ($org['organization_email_sender_code'] ?? ''));
        $sender = $code !== '' && $code !== 'DEFAULT' && isset(BrevoMailer::configured_senders()[$code]) ? $this->configured($code) : null;
        return ['organization_id' => (int) $org['id'], 'organization_name' => $org['organization_name'], 'sender_code' => $sender['code'] ?? null, 'sender' => $sender];
    }

    public function assign(int $orgId, string $code): void
    {
        $this->require_schema();
        if ($code === 'DEFAULT') throw new DomainException('Choose an organization sender address.', 422);
        $this->configured($code);
        $this->mailer()->verified_sender($code);
        $org = $this->db->table('users')->select('id')->where('id', $orgId)->where('role', 'ORGANIZATION')->get();
        if (!$org) throw new DomainException('Organization not found.', 404);
        $this->db->table('users')->where('id', '=', $orgId)->where('role', '=', 'ORGANIZATION')->update(['organization_email_sender_code' => $code]);
    }

    private function locate_fine(string $fineKey): array
    {
        if (preg_match('/^scan-(\d+)$/', $fineKey, $m)) {
            $row = $this->db->raw("SELECT r.id, r.student_id, e.org_user_id FROM org_scan_records r JOIN org_events e ON e.id=r.event_id WHERE r.id=?", [(int) $m[1]])->fetch(PDO::FETCH_ASSOC);
        } elseif (preg_match('/^violation-(\d+)$/', $fineKey, $m)) {
            $row = $this->db->raw('SELECT id, student_id, org_user_id FROM org_violation_records WHERE id=?', [(int) $m[1]])->fetch(PDO::FETCH_ASSOC);
        } else {
            $row = false;
        }
        if (!$row) throw new DomainException('Fine not found.', 404);
        return ['fine_key' => $fineKey, 'student_id' => (int) $row['student_id'], 'org_user_id' => (int) $row['org_user_id']];
    }

    private function student_recipient(int $studentId, int $orgId): array
    {
        $row = $this->db->raw("SELECT s.id,s.student_number,s.full_name,s.course,s.year_level,s.section,u.email
            FROM students s JOIN users u ON u.id=s.user_id
            WHERE s.id=? AND u.role='STUDENT' AND u.status='ACTIVE' AND s.account_status='ACTIVE' AND s.activation_status='ACTIVATED'", [$studentId])->fetch(PDO::FETCH_ASSOC);
        if (!$row || !filter_var($row['email'] ?? null, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('This student has no active registered account with a valid email address.', 409);
        }
        $org = $this->call->model('OrgEventModel')->organization($orgId);
        if ($org['allowed_courses'] !== null && !in_array(strtoupper(trim((string) $row['course'])), $org['allowed_courses'], true)) {
            throw new DomainException('Student is outside your organization.', 403);
        }
        return $row;
    }

    public function prepare_reminder(int $requestingOrgId, string $fineKey): array
    {
        $this->require_schema();
        $located = $this->locate_fine($fineKey);
        if ($located['org_user_id'] !== $requestingOrgId) {
            throw new DomainException('Fine not found for this organization.', 404);
        }
        $org = $this->db->table('users')->select('id,organization_name,organization_email_sender_code')->where('id', $requestingOrgId)->where('role', 'ORGANIZATION')->get();
        if (!$org) throw new DomainException('Organization not found.', 404);
        $code = trim((string) ($org['organization_email_sender_code'] ?? ''));
        if ($code === '' || $code === 'DEFAULT' || !isset(BrevoMailer::configured_senders()[$code])) {
            throw new DomainException('No organization sender email is assigned. Contact the administrator.', 409);
        }
        $student = $this->student_recipient($located['student_id'], $requestingOrgId);
        $fine = null;
        foreach ($this->call->model('PaymentModel')->fines($located['student_id']) as $candidate) {
            if ($candidate['fine_key'] === $fineKey) { $fine = $candidate; break; }
        }
        if (!$fine || (int) $fine['org_user_id'] !== $requestingOrgId) {
            throw new DomainException('Fine not found for this organization.', 404);
        }
        $outstandingCents = (int) round(((float) $fine['outstanding']) * 100);
        if ($outstandingCents <= 0) {
            throw new DomainException('This fine has no outstanding balance.', 409);
        }
        $sender = $this->configured($code);
        $deadline = 'Not scheduled';
        if (!empty($fine['opens_at']) && !empty($fine['closes_at'])) {
            $deadline = $fine['opens_at'] . ' to ' . $fine['closes_at'] . ' (Asia/Manila)';
            if (($fine['payment_window'] ?? '') === 'CLOSED') $deadline .= ' — payment window is currently closed';
            elseif (($fine['payment_window'] ?? '') === 'UPCOMING') $deadline .= ' — payment window has not opened yet';
        }
        $instructions = 'Log in to the MCC-FINES Monitoring System student portal, go to Payments, select ' . $org['organization_name'] . ' and the applicable academic semester, then submit your payment through the available payment method during the open payment window.';
        $message = "Dear {$student['full_name']},\n\n"
            . "This is a fine payment reminder from {$org['organization_name']}.\n\n"
            . "Student: {$student['full_name']} ({$student['student_number']})\n"
            . "Course/Year/Section: {$student['course']} / {$student['year_level']} / {$student['section']}\n"
            . "Academic Semester: " . ($fine['academic_period_label'] ?? 'Legacy / Unassigned') . "\n"
            . "Fine/Event: " . ($fine['violation_name'] ?? 'Fine') . " — " . ($fine['event_name'] ?? '') . "\n"
            . "Recorded At: " . ($fine['recorded_at'] ?? '') . "\n"
            . "Fine Amount (PHP): " . number_format((float) $fine['fine_amount'], 2, '.', '') . "\n"
            . "Payable Amount (PHP): " . number_format((float) ($fine['payable_amount'] ?? $fine['fine_amount']), 2, '.', '') . "\n"
            . "Paid Amount (PHP): " . number_format((float) $fine['paid_amount'], 2, '.', '') . "\n"
            . "Outstanding Balance (PHP): " . number_format((float) $fine['outstanding'], 2, '.', '') . "\n"
            . "Payment Window / Deadline: {$deadline}\n\n"
            . "Payment Instructions:\n{$instructions}\n\n"
            . "If you have already paid or have a pending payment claim, please wait for confirmation or contact the organization for assistance.\n\n"
            . "This is an automated message from {$sender['name']}.";
        $subject = 'Fine payment reminder — ' . $sender['name'];
        return ['sender_code' => $code, 'email' => $student['email'], 'subject' => $subject, 'message' => $message, 'fine_key' => $fineKey];
    }

    public function send_reminder(int $requestingOrgId, string $fineKey, int $actorId): array
    {
        $prepared = $this->prepare_reminder($requestingOrgId, $fineKey);
        $messageId = $this->mailer()->send_notification($prepared['sender_code'], $prepared['email'], $prepared['subject'], $prepared['message']);
        try {
            $this->call->model('ActivityLogModel')->record('Sent fine payment reminder for ' . $fineKey . ' to ' . $prepared['email'] . ' (sender ' . $prepared['sender_code'] . ', message ' . $messageId . ')', $actorId);
        } catch (Throwable $e) {
            error_log('Reminder activity log failed: ' . $e->getMessage());
        }
        return ['message' => 'Fine payment reminder sent.', 'message_id' => $messageId];
    }

    private function window_email_columns_ready(): bool
    {
        $columns = $this->db->raw('SELECT * FROM organization_payment_periods LIMIT 0');
        $found = ['email_notified_at' => false, 'email_notification_hash' => false];
        for ($index = 0; $index < $columns->columnCount(); $index++) {
            $name = $columns->getColumnMeta($index)['name'] ?? '';
            if (array_key_exists($name, $found)) $found[$name] = true;
        }
        return $found['email_notified_at'] && $found['email_notification_hash'];
    }

    public function notify_payment_window(int $orgId, int $windowId, bool $created, bool $importantChange): void
    {
        if (!$created && !$importantChange) return;
        $this->require_schema();
        $window = $this->db->table('organization_payment_periods')->where('id', $windowId)->where('org_user_id', $orgId)->get();
        if (!$window) return;

        $hash = hash('sha256', json_encode([
            'org_user_id' => (int) $window['org_user_id'],
            'academic_period_id' => (int) ($window['academic_period_id'] ?? 0),
            'label' => (string) ($window['label'] ?? ''),
            'semester_start' => (string) ($window['semester_start'] ?? ''),
            'semester_end' => (string) ($window['semester_end'] ?? ''),
            'opens_at' => (string) ($window['opens_at'] ?? ''),
            'closes_at' => (string) ($window['closes_at'] ?? ''),
            'cap_amount' => $window['cap_amount'] === null ? null : (string) $window['cap_amount'],
        ], JSON_THROW_ON_ERROR));

        $tracking = $this->window_email_columns_ready();
        if ($tracking && !$created && ($window['email_notification_hash'] ?? null) === $hash) {
            return; // no important detail change since last notification
        }

        $org = $this->call->model('OrgEventModel')->organization($orgId);
        $code = trim((string) ($this->db->table('users')->select('organization_email_sender_code')->where('id', $orgId)->where('role', 'ORGANIZATION')->get(PDO::FETCH_COLUMN) ?: ''));
        if ($code === '' || $code === 'DEFAULT' || !isset(BrevoMailer::configured_senders()[$code])) {
            error_log('Payment window email skipped: organization sender not assigned (org ' . $orgId . ', window ' . $windowId . ').');
            return;
        }
        $sender = $this->configured($code);
        $this->mailer()->verified_sender($code);

        $params = [];
        $courseFilter = '';
        if ($org['allowed_courses'] !== null) {
            if (!$org['allowed_courses']) return;
            $params = $org['allowed_courses'];
            $courseFilter = ' AND UPPER(TRIM(s.course)) IN (' . implode(',', array_fill(0, count($params), '?')) . ')';
        }

        $rows = $this->db->raw("SELECT DISTINCT u.id, u.email, s.full_name
            FROM students s
            JOIN users u ON u.id = s.user_id
            WHERE u.role='STUDENT' AND u.status='ACTIVE'
              AND s.account_status='ACTIVE' AND s.activation_status='ACTIVATED'
              AND s.year_level <> 'Graduated'
              AND u.email IS NOT NULL AND u.email <> ''" . $courseFilter, $params)->fetchAll(PDO::FETCH_ASSOC);

        $cap = $window['cap_amount'] === null || $window['cap_amount'] === '' ? 'No cap (full assessed amount, subject to rules)' : 'PHP ' . number_format((float) $window['cap_amount'], 2, '.', '');
        $instructions = 'Log in to the MCC-FINES Monitoring System student portal, open the Payments page, select ' . ($org['organization_name'] ?? 'the organization')
            . ' and the applicable academic semester, review your payable fines, and submit your payment through the available payment method within the payment window.';
        $eventLabel = $created ? 'A new payment schedule has been created' : 'A payment schedule has been updated';
        $subject = ($created ? 'Payment schedule: ' : 'Payment schedule updated: ') . ($window['label'] ?? 'Payment Window') . ' — ' . $sender['name'];
        $baseMessage = $eventLabel . " by " . ($org['organization_name'] ?? 'your organization') . ".\n\n"
            . "Payment Title/Purpose: " . ($window['label'] ?? 'Payment Schedule') . "\n"
            . "Organization: " . ($org['organization_name'] ?? '') . "\n"
            . "Academic Semester: " . ($window['label'] ?? '') . " (ID " . (int) ($window['academic_period_id'] ?? 0) . ")\n"
            . "Coverage Period: " . ($window['semester_start'] ?? '') . " to " . ($window['semester_end'] ?? '') . "\n"
            . "Payment Amount / Cap: " . $cap . "\n"
            . "Start Date (Opens): " . ($window['opens_at'] ?? '') . " (Asia/Manila)\n"
            . "Deadline (Closes): " . ($window['closes_at'] ?? '') . " (Asia/Manila)\n\n"
            . "Payment Instructions:\n" . $instructions . "\n\n"
            . "Please check the Payments page for the fines covered by this schedule and your current balance.\n\n"
            . "This is an automated message from " . $sender['name'] . ".";

        $sent = 0; $skipped = 0; $failed = 0;
        foreach ($rows as $recipient) {
            $email = trim((string) ($recipient['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $skipped++; continue; }
            $name = trim((string) ($recipient['full_name'] ?? 'Student'));
            $message = "Dear {$name},\n\n" . $baseMessage;
            try {
                $this->mailer()->send_notification($code, $email, $subject, $message);
                $sent++;
            } catch (Throwable $e) {
                $failed++;
                error_log('Payment window email failed (org ' . $orgId . ', window ' . $windowId . '): ' . $e->getMessage());
            }
        }

        if ($tracking && ($sent > 0 || ($sent === 0 && $failed === 0))) {
            $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
            $this->db->table('organization_payment_periods')->where('id', '=', $windowId)->update([
                'email_notified_at' => $now,
                'email_notification_hash' => $hash,
            ]);
        }
        try {
            $this->call->model('ActivityLogModel')->record('Payment window email notification for window #' . $windowId . ' (' . ($org['organization_name'] ?? 'org') . '): sent=' . $sent . ', skipped=' . $skipped . ', failed=' . $failed, $orgId);
        } catch (Throwable $e) {
            error_log('Payment window email activity log failed: ' . $e->getMessage());
        }
    }
}
