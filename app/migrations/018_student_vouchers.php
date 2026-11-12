<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Student_vouchers
{
    public const KEY = 'student_vouchers';
    public const DESCRIPTION = 'Organization discounts with reasons and per-fine allocations';
    public const DEPENDENCIES = [];

    public function up(?PDO $pdo = null, ?string $root = null): void
    {
        if ($pdo === null) {
            require_once dirname(__DIR__) . '/libraries/FinesMigrationRunner.php';
            FinesMigrationRunner::apply(self::KEY);
            return;
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS student_vouchers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            org_user_id BIGINT UNSIGNED NOT NULL,
            student_id BIGINT UNSIGNED NOT NULL,
            request_key CHAR(36) NOT NULL,
            discount_type VARCHAR(10) NOT NULL,
            discount_value DECIMAL(10,2) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            reason VARCHAR(1000) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY voucher_request (org_user_id, request_key),
            INDEX student_org (student_id, org_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $pdo->exec('CREATE TABLE IF NOT EXISTS student_voucher_items (
            voucher_id BIGINT UNSIGNED NOT NULL,
            fine_key VARCHAR(80) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            PRIMARY KEY (voucher_id, fine_key),
            INDEX voucher_fine (fine_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public function down(): void
    {
        throw new RuntimeException('Voucher history must be preserved.');
    }
}
