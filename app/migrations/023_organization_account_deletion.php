<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Organization_account_deletion
{
    public const KEY = 'organization_account_deletion';
    public const DESCRIPTION = 'Organization account deletion with preserved historical ownership';
    public const DEPENDENCIES = ['activity_log', 'email_otp'];

    public function up(?PDO $pdo = null, ?string $root = null): void
    {
        if ($pdo === null) {
            require_once dirname(__DIR__) . '/libraries/FinesMigrationRunner.php';
            FinesMigrationRunner::apply(self::KEY);
            return;
        }
        $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
        $check->execute(['users', 'organization_deleted_at']);
        if (!$check->fetchColumn()) $pdo->exec('ALTER TABLE users ADD organization_deleted_at DATETIME NULL');
    }

    public function down(): void
    {
        throw new RuntimeException('Deleted organization identities are retained with their history. Automatic rollback is not supported.');
    }
}
