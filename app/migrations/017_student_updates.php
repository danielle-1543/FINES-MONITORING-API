<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Student_updates
{
    public const KEY = 'student_updates';
    public const DESCRIPTION = 'Reason-based student updates and before/after edit history';
    public const DEPENDENCIES = [];

    public function up(?PDO $pdo = null, ?string $root = null): void
    {
        if ($pdo === null) {
            require_once dirname(__DIR__) . '/libraries/FinesMigrationRunner.php';
            FinesMigrationRunner::apply(self::KEY);
            return;
        }
        $column = $pdo->query("SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema=DATABASE() AND table_name='students' AND column_name='departure_reason'");
        if (!$column->fetchColumn()) {
            $pdo->exec('ALTER TABLE students ADD COLUMN departure_reason VARCHAR(30) NULL');
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS student_change_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id BIGINT UNSIGNED NOT NULL,
            changed_by BIGINT UNSIGNED NOT NULL,
            batch_id CHAR(32) NOT NULL,
            reason VARCHAR(30) NOT NULL,
            note VARCHAR(1000) NOT NULL,
            before_values TEXT NOT NULL,
            after_values TEXT NOT NULL,
            changed_at DATETIME NOT NULL,
            INDEX student_changes (student_id, id),
            INDEX change_batches (batch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public function down(): void
    {
        throw new RuntimeException('Student edit history must be preserved.');
    }
}
