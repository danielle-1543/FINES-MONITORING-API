<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Account_settings
{
    public const KEY = 'account_settings';
    public const DESCRIPTION = 'Account session version';
    public const DEPENDENCIES = [];

    public function up(?PDO $pdo = null, ?string $root = null): void
    {
        if ($pdo === null) {
            require_once dirname(__DIR__) . '/libraries/FinesMigrationRunner.php';
            FinesMigrationRunner::apply(self::KEY);
            return;
        }

        $root ??= dirname(__DIR__, 2);
        $q = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='auth_version'");
        if (!$q->fetchColumn()) {
            $pdo->exec('ALTER TABLE users ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 0');
        }
        echo "Account password/session version ready. Existing sessions retained until password changes.\n";
    }

    public function down(): void
    {
        throw new RuntimeException('This migration preserves existing records. Automatic rollback is not supported.');
    }
}
