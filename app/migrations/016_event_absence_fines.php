<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Event_absence_fines
{
    public const KEY = 'event_absence_fines';
    public const DESCRIPTION = 'Configurable absence fine per attendance event';
    public const DEPENDENCIES = [];

    public function up(?PDO $pdo = null, ?string $root = null): void
    {
        if ($pdo === null) {
            require_once dirname(__DIR__) . '/libraries/FinesMigrationRunner.php';
            FinesMigrationRunner::apply(self::KEY);
            return;
        }
        $exists = $pdo->query("SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema=DATABASE() AND table_name='org_events' AND column_name='absence_fine_amount'");
        if (!$exists->fetchColumn()) {
            $pdo->exec('ALTER TABLE org_events ADD COLUMN absence_fine_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Event fine configuration must be preserved with financial records.');
    }
}
