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
}
