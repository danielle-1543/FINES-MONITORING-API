<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Organization_features
{
    public const KEY = 'organization_features';
    public const DESCRIPTION = 'Organization scope and violation records';
    public const DEPENDENCIES = [];

    public function up(?PDO $pdo = null, ?string $root = null): void
    {
        if ($pdo === null) {
            require_once dirname(__DIR__) . '/libraries/FinesMigrationRunner.php';
            FinesMigrationRunner::apply(self::KEY);
            return;
        }

        $root ??= dirname(__DIR__, 2);
        $scopeColumnsAdded = false;
        foreach ([
            'organization_scope' => "ENUM('ALL','COURSE') NOT NULL DEFAULT 'COURSE'",
            'organization_course' => 'VARCHAR(100) NULL',
        ] as $column => $definition) {
            $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
            $query->execute([
                'users',
                $column
            ]);
            if (!$query->fetchColumn()) {
                $pdo->exec("ALTER TABLE users ADD COLUMN {$column} {$definition}");
                $scopeColumnsAdded = true;
            }
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS org_violation_records (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            org_user_id INT UNSIGNED NOT NULL,
            student_id BIGINT UNSIGNED NOT NULL,
            event_id BIGINT UNSIGNED NULL,
            scan_record_id BIGINT UNSIGNED NULL,
            violation_type_id BIGINT UNSIGNED NOT NULL,
            violation_name VARCHAR(120) NOT NULL,
            fine_amount DECIMAL(10,2) NOT NULL,
            recorded_by INT UNSIGNED NOT NULL,
            recorded_at DATETIME NOT NULL,
            UNIQUE KEY uq_scan_violation (scan_record_id, violation_type_id),
            KEY idx_org_violation_student (org_user_id, student_id, recorded_at),
            CONSTRAINT fk_org_violation_org FOREIGN KEY (org_user_id) REFERENCES users(id),
            CONSTRAINT fk_org_violation_student FOREIGN KEY (student_id) REFERENCES students(id),
            CONSTRAINT fk_org_violation_event FOREIGN KEY (event_id) REFERENCES org_events(id),
            CONSTRAINT fk_org_violation_scan FOREIGN KEY (scan_record_id) REFERENCES org_scan_records(id),
            CONSTRAINT fk_org_violation_type FOREIGN KEY (violation_type_id) REFERENCES org_violation_types(id),
            CONSTRAINT fk_org_violation_recorder FOREIGN KEY (recorded_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->beginTransaction();
        try {
            if ($scopeColumnsAdded) {
                $pdo->exec("UPDATE users SET organization_scope = 'ALL', organization_course = NULL
                    WHERE role = 'ORGANIZATION' AND (LOWER(username) = 'usg' OR UPPER(TRIM(organization_name)) IN ('USG','UNIVERSITY STUDENT GOVERNMENT'))");
            }
            foreach ([
                'CAS',
                'CCS',
                'BSED',
                'BTVTED',
                'BTLED',
                'CRIM'
            ] as $course) {
                $username = strtolower($course);
                $find = $pdo->prepare('SELECT id, role, organization_course FROM users WHERE username = ?');
                $find->execute([
                    $username
                ]);
                $existing = $find->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    if ($existing['role'] !== 'ORGANIZATION') {
                        throw new RuntimeException("Username {$username} is already used by a different role; no account was replaced.");
                    }
                    if ($scopeColumnsAdded) {
                        $pdo->prepare("UPDATE users SET organization_scope = 'COURSE', organization_course = ? WHERE id = ?")->execute([
                            $course,
                            $existing['id'],
                        ]);
                    }
                    echo "Kept existing {$username} account and password.\n";
                    continue;
                }
                $pdo->prepare("INSERT INTO users (username,email,password_hash,role,organization_name,status,organization_scope,organization_course)
                    VALUES (?,?,?,'ORGANIZATION',?,'ACTIVE','COURSE',?)")->execute([
                    $username,
                    $username . '@sample.fines.local',
                    password_hash($username . '123', PASSWORD_DEFAULT),
                    $course,
                    $course,
                ]);
                echo "Created sample account {$username}.\n";
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        echo "Organization scope and violation storage ready. Existing events and scans preserved.\n";
    }

    public function down(): void
    {
        throw new RuntimeException('This migration preserves existing records. Automatic rollback is not supported.');
    }
}
