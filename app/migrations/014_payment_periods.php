<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Payment_periods
{
    public const KEY = 'payment_periods';
    public const DESCRIPTION = 'Organization semester payment windows and optional student caps';
    public const DEPENDENCIES = [];

    public function up(?PDO $pdo = null, ?string $root = null): void
    {
        if ($pdo === null) {
            require_once dirname(__DIR__) . '/libraries/FinesMigrationRunner.php';
            FinesMigrationRunner::apply(self::KEY);
            return;
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS organization_payment_periods (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            org_user_id INT NOT NULL,
            label VARCHAR(100) NOT NULL,
            semester_start DATE NOT NULL,
            semester_end DATE NOT NULL,
            opens_at DATETIME NOT NULL,
            closes_at DATETIME NOT NULL,
            cap_amount DECIMAL(10,2) NULL,
            INDEX organization_semester (org_user_id, semester_start, semester_end)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public function down(): void
    {
        throw new RuntimeException('Payment schedules must be preserved with their financial records.');
    }
}
