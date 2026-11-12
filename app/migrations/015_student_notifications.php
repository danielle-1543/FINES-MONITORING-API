<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Student_notifications
{
    public const KEY = 'student_notifications';
    public const DESCRIPTION = 'Student notification inbox and queued OneSignal delivery';
    public const DEPENDENCIES = [];

    public function up(?PDO $pdo = null, ?string $root = null): void
    {
        if ($pdo === null) {
            require_once dirname(__DIR__) . '/libraries/FinesMigrationRunner.php';
            FinesMigrationRunner::apply(self::KEY);
            return;
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS student_notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            source_key VARCHAR(100) NOT NULL,
            title VARCHAR(150) NOT NULL,
            message TEXT NOT NULL,
            kind VARCHAR(30) NOT NULL,
            recorded_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            read_at DATETIME NULL,
            push_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            push_attempts INT NOT NULL DEFAULT 0,
            push_next_attempt_at DATETIME NOT NULL,
            push_idempotency_key CHAR(36) NOT NULL,
            push_provider_id VARCHAR(100) NULL,
            push_last_error VARCHAR(150) NULL,
            UNIQUE KEY notification_source (source_key),
            KEY student_inbox (user_id, id),
            KEY student_unread (user_id, read_at),
            KEY notification_delivery (push_status, push_next_attempt_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(): void
    {
        throw new RuntimeException('Notification history must be preserved.');
    }
}
