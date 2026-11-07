<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Attendance
{
    public const KEY = 'attendance';
    public const DESCRIPTION = 'Attendance statuses and expected rosters';
    public const DEPENDENCIES = [];

    public function up(?PDO $pdo = null, ?string $root = null): void
    {
        if ($pdo === null) {
            require_once dirname(__DIR__) . '/libraries/FinesMigrationRunner.php';
            FinesMigrationRunner::apply(self::KEY);
            return;
        }

        $root ??= dirname(__DIR__, 2);
        foreach ([
            'attendance_status' => "ALTER TABLE org_scan_records ADD COLUMN attendance_status ENUM('PRESENT','ABSENT') NOT NULL DEFAULT 'PRESENT'",
            'absence_enabled' => "ALTER TABLE org_events ADD COLUMN absence_enabled TINYINT(1) NOT NULL DEFAULT 0",
            'attendance_finalized_at' => "ALTER TABLE org_events ADD COLUMN attendance_finalized_at DATETIME NULL"
        ] as $column => $sql) {
            $table = $column === 'attendance_status' ? 'org_scan_records' : 'org_events';
            $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
            $q->execute([
                $table,
                $column
            ]);
            if (!$q->fetchColumn()) {
                $pdo->exec($sql);
            }
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS org_event_roster (event_id BIGINT UNSIGNED NOT NULL,student_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(event_id,student_id),FOREIGN KEY(event_id) REFERENCES org_events(id),FOREIGN KEY(student_id) REFERENCES students(id)) ENGINE=InnoDB");
        echo "Attendance absence tracking ready; historical events unchanged.\n";
    }

    public function down(): void
    {
        throw new RuntimeException('This migration preserves existing records. Automatic rollback is not supported.');
    }
}
