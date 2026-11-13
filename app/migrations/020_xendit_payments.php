<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Xendit_payments
{
    public const KEY = 'xendit_payments';
    public const DESCRIPTION = 'Xendit test checkout sessions and unique provider references';
    public const DEPENDENCIES = ['payment_allocations'];

    public function up(?PDO $pdo = null, ?string $root = null): void
    {
        if ($pdo === null) {
            require_once dirname(__DIR__) . '/libraries/FinesMigrationRunner.php';
            FinesMigrationRunner::apply(self::KEY);
            return;
        }
        $pdo->exec("ALTER TABLE payment_claims MODIFY method ENUM('CASH','GCASH','XENDIT_TEST') NOT NULL");
        $pdo->exec("CREATE TABLE IF NOT EXISTS xendit_payments (
            claim_id BIGINT UNSIGNED PRIMARY KEY,
            reference_id VARCHAR(64) NOT NULL UNIQUE,
            session_id VARCHAR(100) NULL UNIQUE,
            payment_id VARCHAR(100) NULL UNIQUE,
            checkout_url VARCHAR(1000) NULL,
            state VARCHAR(20) NOT NULL DEFAULT 'CREATING',
            mode VARCHAR(10) NOT NULL DEFAULT 'test',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            FOREIGN KEY (claim_id) REFERENCES payment_claims(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(): void
    {
        throw new RuntimeException('Payment records are preserved; automatic rollback is not supported.');
    }
}
