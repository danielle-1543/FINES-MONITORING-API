<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Activity_log
{
    public const KEY = 'activity_log';
    public const DESCRIPTION = 'System activity log';
    public const DEPENDENCIES = [];

    public function up(?PDO $pdo = null, ?string $root = null): void
    {
        if ($pdo === null) {
            require_once dirname(__DIR__) . '/libraries/FinesMigrationRunner.php';
            FinesMigrationRunner::apply(self::KEY);
            return;
        }

        $root ??= dirname(__DIR__, 2);
        $pdo->exec("CREATE TABLE IF NOT EXISTS activity_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,actor_id BIGINT UNSIGNED NULL,
            actor_name VARCHAR(150) NOT NULL,actor_role VARCHAR(30) NOT NULL,action VARCHAR(150) NOT NULL,
            created_at DATETIME NOT NULL,INDEX activity_created (created_at,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "Activity log ready. Existing records unchanged.\n";
    }

    public function down(): void
    {
        throw new RuntimeException('This migration preserves existing records. Automatic rollback is not supported.');
    }
}
