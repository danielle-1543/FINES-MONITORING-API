<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class NotificationModel extends Model
{
    private function now(): string
    {
        return (new DateTimeImmutable(
            'now',
            new DateTimeZone('Asia/Manila')
        ))->format('Y-m-d H:i:s');
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(bin2hex($bytes), 4)
        );
    }

    private function save(
        array $record,
        string $source,
        string $kind,
        string $title,
        string $message
    ): void {
        if (empty($record['user_id'])) {
            return;
        }

        $now = $this->now();

        // One source record can create only one inbox notification.
        $this->db->raw(
            'INSERT IGNORE INTO student_notifications
                (
                    user_id, source_key, title, message, kind,
                    recorded_at, created_at,
                    push_next_attempt_at, push_idempotency_key
                )
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $record['user_id'],
                $source,
                $title,
                $message,
                $kind,
                $record['recorded_at'],
                $now,
                $now,
                $this->uuid(),
            ]
        );
    }

    public function payment_window(int $orgId, int $windowId, array $academic, string $opens, string $closes): void
    {
        $org = $this->call->model('OrgEventModel')->organization($orgId);
        $query = $this->db->table('students')->select('user_id')->where('account_status', 'ACTIVE')
            ->where('current_academic_period_id', $academic['id'])->where('year_level', '<>', 'Graduated');
        if ($org['allowed_courses'] !== null) {
            if (!$org['allowed_courses']) return;
            $query->in('course', $org['allowed_courses']);
        }
        $now = $this->now();
        $revision = bin2hex(random_bytes(16));
        foreach ($query->get_all() as $student) {
            $this->save(['user_id' => $student['user_id'], 'recorded_at' => $now],
                'payment-window:' . $windowId . ':' . hash('sha256', $student['user_id'] . ':' . $revision),
                'PAYMENT_WINDOW', 'Payment window scheduled',
                ($org['organization_name'] ?? 'Your organization') . ' · ' . $academic['label']
                . ': payments are accepted from ' . $opens . ' until ' . $closes
                . ' (Philippine time). New fines are locked while this payment window is open.');
        }
    }

    public function record_scan(int $scanId): void
    {
        $record = $this->db->raw(
            "SELECT
                r.*,
                s.user_id,
                e.name AS event_name,
                u.organization_name,
                COALESCE(v.name, 'Violation') AS violation_name
             FROM org_scan_records r
             JOIN students s ON s.id = r.student_id
             JOIN org_events e ON e.id = r.event_id
             JOIN users u ON u.id = e.org_user_id
             LEFT JOIN org_violation_types v
                ON v.id = e.violation_type_id
             WHERE r.id = ?",
            [$scanId]
        )->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            return;
        }

        $event = $record['event_name'];
        $organization = $record['organization_name'];
        $fine = (float) $record['fine_amount'];
        $amount = '₱' . number_format($fine, 2);

        if ($record['record_type'] === 'ATTENDANCE') {
            $absent = $record['attendance_status'] === 'ABSENT';
            $kind = $absent ? 'ABSENT' : 'PRESENT';
            $title = $absent
                ? 'Absence recorded'
                : 'Attendance recorded';

            $message = 'You were recorded '
                . ($absent ? 'absent' : 'present')
                . ' in "' . $event . '" conducted by '
                . $organization . '.';

            $message .= $fine > 0
                ? ' You were fined ' . $amount . '.'
                : ' No attendance fine was charged.';
        } else {
            $kind = 'VIOLATION';
            $title = 'Violation recorded';
            $message = 'You were recorded for "'
                . $record['violation_name']
                . '" during "' . $event
                . '" conducted by ' . $organization
                . '. Fine: ' . $amount . '.';
        }

        $this->save(
            $record,
            'scan:' . $scanId,
            $kind,
            $title,
            $message
        );
    }

    public function record_violation(int $violationId): void
    {
        $record = $this->db->raw(
            'SELECT
                r.*,
                s.user_id,
                e.name AS event_name,
                u.organization_name
             FROM org_violation_records r
             JOIN students s ON s.id = r.student_id
             JOIN users u ON u.id = r.org_user_id
             LEFT JOIN org_events e ON e.id = r.event_id
             WHERE r.id = ?',
            [$violationId]
        )->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            return;
        }

        $message = 'You were recorded for "'
            . $record['violation_name']
            . '" by ' . $record['organization_name'];

        if ($record['event_name']) {
            $message .= ' during "' . $record['event_name'] . '"';
        }

        $message .= '. Fine: ₱'
            . number_format((float) $record['fine_amount'], 2)
            . '.';

        $this->save(
            $record,
            'violation:' . $violationId,
            'VIOLATION',
            'Violation recorded',
            $message
        );
    }

    public function record_absences(int $eventId): void
    {
        $ids = $this->db->table('org_scan_records')
            ->select('id')
            ->where('event_id', $eventId)
            ->where('attendance_status', 'ABSENT')
            ->get_all(PDO::FETCH_COLUMN);

        foreach ($ids as $id) {
            $this->record_scan((int) $id);
        }
    }

    public function unread_count(int $userId): int
    {
        return (int) $this->db->raw(
            'SELECT COUNT(*)
             FROM student_notifications
             WHERE user_id = ? AND read_at IS NULL',
            [$userId]
        )->fetchColumn();
    }

    public function inbox(int $userId, int $requestedPage, string $filter = 'all'): array
    {
        if (!in_array($filter, ['all', 'unread', 'read'], true)) throw new DomainException('Choose All, Unread or Read notifications.', 422);
        $query = function () use ($userId, $filter) {
            $query = $this->db->table('student_notifications')->where('user_id', $userId);
            if ($filter === 'unread') $query->where_null('read_at');
            if ($filter === 'read') $query->where_not_null('read_at');
            return $query;
        };
        $total = $query()->count();
        $pages = max(1, (int) ceil($total / 20));
        $page = min(max(1, $requestedPage), $pages);
        $items = $query()->select('id,title,message,kind,recorded_at,created_at,read_at')
            ->order_by('id', 'DESC')->limit(20, ($page - 1) * 20)->get_all();
        return ['data' => $items, 'unread_count' => $this->unread_count($userId), 'filter' => $filter,
            'pagination' => ['page' => $page, 'pages' => $pages, 'total' => $total]];
    }

    public function mark_read(int $userId, array $input): void
    {
        if (($input['all'] ?? false) === true) {
            $this->db->table('student_notifications')
                ->where('user_id', $userId)
                ->where_null('read_at')
                ->update(['read_at' => $this->now()]);

            return;
        }

        $id = filter_var(
            $input['id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (!$id) {
            throw new DomainException(
                'Choose a valid notification.',
                422
            );
        }

        $notification = $this->db->table('student_notifications')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->get();

        if (!$notification) {
            throw new DomainException(
                'Notification not found.',
                404
            );
        }

        $this->db->table('student_notifications')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->where_null('read_at')
            ->update(['read_at' => $this->now()]);
    }

    protected function send_push(array $notification): ?string
    {
        $appId = trim(getenv('ONESIGNAL_APP_ID') ?: '');
        $apiKey = trim(getenv('ONESIGNAL_REST_API_KEY') ?: '');
        $secret = getenv('JWT_SECRET') ?: '';

        if (
            $appId === '' ||
            $apiKey === '' ||
            strlen($secret) < 32 ||
            !function_exists('curl_init')
        ) {
            throw new RuntimeException(
                'Push configuration or PHP cURL is unavailable.'
            );
        }

        // Must match the identity generated by push_identity().
        $externalId = hash_hmac(
            'sha256',
            'onesignal:' . $appId . ':' . $notification['user_id'],
            $secret
        );

        $payload = [
            'app_id' => $appId,
            'include_aliases' => [
                'external_id' => [$externalId],
            ],
            'target_channel' => 'push',
            'isAnyWeb' => true,
            'headings' => [
                'en' => $notification['title'],
            ],
            'contents' => [
                'en' => $notification['message'],
            ],
            'data' => [
                'notification_id' => (string) $notification['id'],
            ],
            'idempotency_key' =>
                $notification['push_idempotency_key'],
        ];

        $frontend = rtrim(getenv('FRONTEND_URL') ?: '', '/');

        if ($frontend !== '') {
            $payload['web_url'] = $frontend . '/#notifications';
        }

        $handle = curl_init(
            'https://api.onesignal.com/notifications?c=push'
        );

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Key ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS =>
                json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        // WAMP/OpenSSL may have no CA bundle configured. Use Windows' trusted
        // certificate store, retaining peer and hostname verification.
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($handle, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }

        $body = curl_exec($handle);
        $transportError = curl_errno($handle);
        $status = (int) curl_getinfo(
            $handle,
            CURLINFO_HTTP_CODE
        );

        curl_close($handle);

        if ($transportError !== 0) {
            throw new RuntimeException(
                'Push HTTPS connection failed (cURL ' . $transportError . ').'
            );
        }

        $result = is_string($body)
            ? json_decode($body, true)
            : null;

        if (
            $status < 200 ||
            $status >= 300 ||
            !is_array($result)
        ) {
            throw new RuntimeException(
                'Push provider request failed (HTTP '
                . $status . ').'
            );
        }

        // No ID means OneSignal found no eligible subscribed device.
        return !empty($result['id'])
            ? (string) $result['id']
            : null;
    }

    public function deliver_pending(?int $userId = null): int
    {
        if (
            !getenv('ONESIGNAL_APP_ID') ||
            !getenv('ONESIGNAL_REST_API_KEY')
        ) {
            return 0;
        }

        // Concurrent controller requests must not deliver the same queue item.
        $database = (string) $this->db->raw(
            'SELECT DATABASE()'
        )->fetchColumn();

        $lock = 'fines_push_' . substr(
            hash('sha256', $database),
            0,
            32
        );

        $locked = (int) $this->db->raw(
            'SELECT GET_LOCK(?, 0)',
            [$lock]
        )->fetchColumn();

        if ($locked !== 1) {
            return 0;
        }

        $sent = 0;

        try {
            $this->db->raw(
                "UPDATE student_notifications
                 SET push_status = 'failed'
                 WHERE push_status = 'pending'
                   AND push_attempts >= 6"
            );

            $userFilter = $userId === null ? '' : ' AND n.user_id = ?';
            $params = [$this->now()];
            if ($userId !== null) {
                $params[] = $userId;
            }
            $items = $this->db->raw(
                "SELECT n.*
                 FROM student_notifications n
                 JOIN users u ON u.id = n.user_id
                 JOIN students s ON s.user_id = u.id
                 WHERE n.push_status = 'pending'
                   AND n.push_attempts < 6
                   AND n.push_next_attempt_at <= ?
                   AND u.role = 'STUDENT'
                   AND u.status = 'ACTIVE'
                   AND s.account_status = 'ACTIVE'
                   AND s.activation_status = 'ACTIVATED'
                 " . $userFilter . "
                 ORDER BY n.id
                 LIMIT 1",
                $params
            )->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as $item) {
                $attempt = (int) $item['push_attempts'] + 1;

                $nextAttempt = (
                    new DateTimeImmutable(
                        'now',
                        new DateTimeZone('Asia/Manila')
                    )
                )->modify('+1 minute')->format('Y-m-d H:i:s');

                $this->db->table('student_notifications')
                    ->where('id', $item['id'])
                    ->update([
                        'push_attempts' => $attempt,
                        'push_next_attempt_at' => $nextAttempt,
                    ]);

                try {
                    $providerId = $this->send_push($item);

                    $this->db->table('student_notifications')
                        ->where('id', $item['id'])
                        ->update([
                            'push_status' => $providerId
                                ? 'sent'
                                : 'unsubscribed',
                            'push_provider_id' => $providerId,
                            'push_last_error' => null,
                        ]);

                    $sent += $providerId ? 1 : 0;
                } catch (Throwable $error) {
                    $this->db->table('student_notifications')
                        ->where('id', $item['id'])
                        ->update([
                            'push_status' => $attempt >= 6
                                ? 'failed'
                                : 'pending',
                            'push_last_error' => substr(
                                $error->getMessage(),
                                0,
                                150
                            ),
                        ]);
                }
            }
        } finally {
            $this->db->raw(
                'SELECT RELEASE_LOCK(?)',
                [$lock]
            );
        }

        return $sent;
    }
}
