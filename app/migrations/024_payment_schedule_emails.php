<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Payment_schedule_emails
{
    public const KEY = 'payment_schedule_emails';
    public const DESCRIPTION = 'Durable payment announcement batches on existing schedules';
    public const DEPENDENCIES = ['payment_periods', 'academic_periods'];

    public function up(?PDO $pdo = null, ?string $root = null): void
    {
        if ($pdo === null) {
            require_once dirname(__DIR__) . '/libraries/FinesMigrationRunner.php';
            FinesMigrationRunner::apply(self::KEY);
            return;
        }
        foreach (['email_status' => 'VARCHAR(20) NULL', 'email_announcement' => 'LONGTEXT NULL'] as $column => $type) {
            $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
            $check->execute(['organization_payment_periods', $column]);
            if (!$check->fetchColumn()) {
                $pdo->exec("ALTER TABLE organization_payment_periods ADD {$column} {$type}");
            }
        }
    }
}
