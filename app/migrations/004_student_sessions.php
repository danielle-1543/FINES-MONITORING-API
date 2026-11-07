<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Student_sessions
{
    public const KEY = 'student_sessions';
    public const DESCRIPTION = 'Student access-token session tracking';
    public const DEPENDENCIES = [];

    public function up(?PDO $pdo = null, ?string $root = null): void
    {
        if ($pdo === null) {
            require_once dirname(__DIR__) . '/libraries/FinesMigrationRunner.php';
            FinesMigrationRunner::apply(self::KEY);
            return;
        }

        $root ??= dirname(__DIR__, 2);
        $column = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='refresh_tokens' AND column_name='access_jti'")->fetchColumn();
        if (!$column) {
            $pdo->exec('ALTER TABLE refresh_tokens ADD COLUMN access_jti VARCHAR(64) NULL, ADD INDEX idx_student_access_jti (access_jti)');
        }
        echo "Student login session tracking ready. Existing accounts and tokens preserved.\n";
    }

    public function down(): void
    {
        throw new RuntimeException('This migration preserves existing records. Automatic rollback is not supported.');
    }
}
