<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Payment_allocations
{
    public const KEY = 'payment_allocations';
    public const DESCRIPTION = 'Per-fine payment allocations';
    public const DEPENDENCIES = ['payments'];

    public function up(?PDO $pdo = null, ?string $root = null): void
    {
        if ($pdo === null) {
            require_once dirname(__DIR__) . '/libraries/FinesMigrationRunner.php';
            FinesMigrationRunner::apply(self::KEY);
            return;
        }

        $root ??= dirname(__DIR__, 2);
        $pdo->exec("CREATE TABLE IF NOT EXISTS payment_claim_items (claim_id BIGINT UNSIGNED NOT NULL,fine_key VARCHAR(50) NOT NULL,amount DECIMAL(10,2) NOT NULL,PRIMARY KEY(claim_id,fine_key),FOREIGN KEY(claim_id) REFERENCES payment_claims(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "Payment claim allocations ready. Existing claims preserved.\n";
    }

    public function down(): void
    {
        throw new RuntimeException('This migration preserves existing records. Automatic rollback is not supported.');
    }
}
