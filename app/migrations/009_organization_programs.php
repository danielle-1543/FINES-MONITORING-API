<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Organization_programs
{
    public const KEY = 'organization_programs';
    public const DESCRIPTION = 'Multiple programs per organization';
    public const DEPENDENCIES = ['organization_features'];

    public function up(?PDO $pdo = null, ?string $root = null): void
    {
        if ($pdo === null) {
            require_once dirname(__DIR__) . '/libraries/FinesMigrationRunner.php';
            FinesMigrationRunner::apply(self::KEY);
            return;
        }

        $root ??= dirname(__DIR__, 2);
        $idType = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='id'")->fetchColumn();
        if (!preg_match('/^(?:bigint|int|smallint)(?:\(\d+\))?(?: unsigned)?$/i', (string) $idType)) {
            throw new RuntimeException('Unsupported user ID type.');
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS organization_programs (org_user_id {$idType} NOT NULL,program VARCHAR(100) NOT NULL,PRIMARY KEY(org_user_id,program),FOREIGN KEY(org_user_id) REFERENCES users(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "Multiple-program organization access ready. Existing scopes preserved.\n";
    }

    public function down(): void
    {
        throw new RuntimeException('This migration preserves existing records. Automatic rollback is not supported.');
    }
}
