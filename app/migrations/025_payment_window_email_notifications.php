<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Payment_window_email_notifications
{
    public const KEY = 'payment_window_email_notifications';
    public const DESCRIPTION = 'Payment window email notification tracking';
    public const DEPENDENCIES = [];

    public function up(?PDO $pdo = null, ?string $root = null): void
    {
        if ($pdo === null) {
            require_once dirname(__DIR__) . '/libraries/FinesMigrationRunner.php';
            FinesMigrationRunner::apply(self::KEY);
            return;
        }

        foreach ([
            'email_notified_at' => 'DATETIME NULL',
            'email_notification_hash' => 'VARCHAR(64) NULL',
        ] as $column => $definition) {
            $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
            $query->execute(['organization_payment_periods', $column]);
            if (!$query->fetchColumn()) {
                $pdo->exec("ALTER TABLE organization_payment_periods ADD COLUMN {$column} {$definition}");
            }
        }

        echo "Payment window email notification tracking ready.\n";
    }

    public function down(): void
    {
        throw new RuntimeException('Payment schedules must be preserved with their financial records.');
    }
}
