<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Payments
{
    public const KEY = 'payments';
    public const DESCRIPTION = 'Payment claims and unique active references';
    public const DEPENDENCIES = [];

    public function up(?PDO $pdo = null, ?string $root = null): void
    {
        if ($pdo === null) {
            require_once dirname(__DIR__) . '/libraries/FinesMigrationRunner.php';
            FinesMigrationRunner::apply(self::KEY);
            return;
        }

        $root ??= dirname(__DIR__, 2);
        $pdo->exec("CREATE TABLE IF NOT EXISTS payment_claims (
         id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
         student_id BIGINT UNSIGNED NOT NULL, org_user_id INT UNSIGNED NOT NULL,
         fine_key VARCHAR(50) NOT NULL, amount DECIMAL(10,2) NOT NULL,
         method ENUM('CASH','GCASH') NOT NULL, reference_number VARCHAR(100) NULL,
         proof_file VARCHAR(100) NULL, proof_mime VARCHAR(50) NULL,
         status ENUM('PENDING','CONFIRMED','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING',
         submitted_at DATETIME NOT NULL, reviewed_at DATETIME NULL, reviewed_by INT UNSIGNED NULL,
         review_note VARCHAR(500) NULL, receipt_number VARCHAR(70) NULL,
         UNIQUE KEY uq_payment_receipt (receipt_number), KEY idx_student_fine (student_id,fine_key,status),
         KEY idx_org_status (org_user_id,status),
         FOREIGN KEY(student_id) REFERENCES students(id), FOREIGN KEY(org_user_id) REFERENCES users(id),
         FOREIGN KEY(reviewed_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $exists = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payment_claims' AND column_name='active_reference'")->fetchColumn();
        if (!$exists) {
            $pdo->exec("ALTER TABLE payment_claims ADD COLUMN active_reference VARCHAR(100) GENERATED ALWAYS AS (CASE WHEN method='GCASH' AND status IN ('PENDING','CONFIRMED') THEN reference_number ELSE NULL END) STORED, ADD UNIQUE KEY uq_active_gcash_reference (active_reference)");
        }
        echo "Payment claims storage ready. Existing data preserved.\n";
    }

    public function down(): void
    {
        throw new RuntimeException('This migration preserves existing records. Automatic rollback is not supported.');
    }
}
