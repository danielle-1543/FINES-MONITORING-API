<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
class Academic_periods
{
    public const KEY = 'academic_periods';
    public const DESCRIPTION = 'Academic calendar, enrollment snapshots and immutable fine semester references';
    public const DEPENDENCIES = ['student_updates', 'xendit_payments', 'payment_periods', 'student_vouchers'];
    public function up(?PDO $pdo = null, ?string $root = null): void
    {
        if ($pdo === null) {
            require_once dirname(__DIR__) . '/libraries/FinesMigrationRunner.php';
            FinesMigrationRunner::apply(self::KEY); return;
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS academic_periods (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, year_start SMALLINT UNSIGNED NOT NULL,
            term VARCHAR(10) NOT NULL, label VARCHAR(100) NOT NULL,
            starts_on DATE NULL, ends_on DATE NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'OPEN', is_current TINYINT NOT NULL DEFAULT 0,
            UNIQUE KEY academic_term (year_start,term)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS student_enrollments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, student_id BIGINT UNSIGNED NOT NULL,
            academic_period_id INT UNSIGNED NOT NULL, course VARCHAR(100) NOT NULL,
            year_level VARCHAR(30) NOT NULL, section VARCHAR(50) NOT NULL,
            enrollment_status VARCHAR(20) NOT NULL DEFAULT 'ENROLLED', created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL, UNIQUE KEY student_term (student_id,academic_period_id),
            FOREIGN KEY(student_id) REFERENCES students(id), FOREIGN KEY(academic_period_id) REFERENCES academic_periods(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS academic_record_changes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, changed_by BIGINT UNSIGNED NOT NULL,
            academic_period_id INT UNSIGNED NOT NULL, source_kind VARCHAR(20) NOT NULL,
            source_id BIGINT UNSIGNED NOT NULL, change_details TEXT NOT NULL, changed_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach (['students' => 'current_academic_period_id', 'org_events' => 'academic_period_id',
            'org_scan_records' => 'academic_period_id', 'org_violation_records' => 'academic_period_id',
            'organization_payment_periods' => 'academic_period_id', 'payment_claims' => 'academic_period_id'] as $table => $column) {
            $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
            $check->execute([$table, $column]);
            if (!$check->fetchColumn()) $pdo->exec("ALTER TABLE `$table` ADD `$column` INT UNSIGNED NULL, ADD INDEX (`$column`), ADD FOREIGN KEY (`$column`) REFERENCES academic_periods(id)");
        }
        foreach (['org_scan_records', 'org_violation_records'] as $table) {
            $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
            $check->execute([$table, 'legacy_assessment']);
            if (!$check->fetchColumn()) $pdo->exec("ALTER TABLE `$table` ADD legacy_assessment TINYINT NOT NULL DEFAULT 1");
        }
        // No guessed semesters, automatic reassignment, balance changes, or new
        // students/accounts/QRs. Existing rows remain Legacy / Unassigned.
    }
    public function down(): void { throw new RuntimeException('Academic and financial history must be preserved.'); }
}
