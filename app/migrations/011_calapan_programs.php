<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Calapan_programs
{
    public const KEY = 'calapan_programs';
    public const DESCRIPTION = 'Calapan program and section labels';
    public const DEPENDENCIES = ['organization_features'];

    public function up(?PDO $pdo = null, ?string $root = null): void
    {
        if ($pdo === null) {
            require_once dirname(__DIR__) . '/libraries/FinesMigrationRunner.php';
            FinesMigrationRunner::apply(self::KEY);
            return;
        }

        $root ??= dirname(__DIR__, 2);
        $pdo->beginTransaction();
        try {
            $before = $pdo->query("SELECT id,student_number,course,section,year_level FROM students WHERE course IN ('CCS','CRIM') OR UPPER(section) LIKE '%TEST%' ORDER BY id FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
            $dir = $root . '/runtime/program-migrations';
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            if ($before) {
                $backup = $dir . '/before-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.json';
                if (file_put_contents($backup, json_encode($before, JSON_PRETTY_PRINT)) === false) {
                    throw new RuntimeException('Unable to save pre-change labels.');
                }
            }
            $sections = [
                'TEST-A' => 1,
                'TEST-B' => 2,
                'TEST-C' => 3,
                'TEST 1' => 1,
                'TEST 2' => 2,
                'TEST 3' => 3,
                'TEST1' => 1,
                'TEST2' => 2,
                'TEST3' => 3,
                'TEST-1' => 1,
                'TEST-2' => 2,
                'TEST-3' => 3
            ];
            $updates = 0;
            foreach ($before as $row) {
                $course = $row['course'] === 'CCS' ? 'BSIT' : ($row['course'] === 'CRIM' ? 'BSCRIM' : $row['course']);
                $section = $row['section'];
                $key = strtoupper(trim($section));
                if (isset($sections[$key]) && $course === 'BSIT') {
                    if (!preg_match('/^[1-4]/', $row['year_level'], $year)) {
                        throw new RuntimeException('Cannot determine year level for section rename.');
                    }
                    $section = $year[0] . 'F' . $sections[$key];
                }
                if ($course !== $row['course'] || $section !== $row['section']) {
                    $pdo->prepare('UPDATE students SET course=?,section=? WHERE id=?')->execute([
                        $course,
                        $section,
                        $row['id']
                    ]);
                    $updates++;
                }
            }
            foreach ([
                'CBM',
                'CTE',
                'CCJE'
            ] as $college) {
                $username = strtolower($college);
                $q = $pdo->prepare('SELECT id,role FROM users WHERE username=? FOR UPDATE');
                $q->execute([
                    $username
                ]);
                $existing = $q->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    if ($existing['role'] !== 'ORGANIZATION') {
                        throw new RuntimeException('College username already belongs to another role.');
                    }
                    continue;
                }
                $pdo->prepare("INSERT INTO users(username,email,password_hash,role,organization_name,status,organization_scope,organization_course) VALUES(?,?,?,'ORGANIZATION',?,'ACTIVE','COURSE',?)")->execute([
                    $username,
                    $username . '@sample.fines.local',
                    password_hash($username . '123', PASSWORD_BCRYPT),
                    $college,
                    $college
                ]);
                echo "Created {$college} sample organization.\n";
            }
            $pdo->commit();
            echo "Updated {$updates} student program/section labels. IDs, QR codes, accounts and history preserved.\n";
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $manifest = $root . '/runtime/test-fixtures/attendance-students.json';
        if (is_file($manifest)) {
            $fixtures = json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
            $q = $pdo->prepare('SELECT course,section FROM students WHERE student_number=?');
            foreach ($fixtures as & $fixture) {
                $q->execute([
                    $fixture['student_number']
                ]);
                $row = $q->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $fixture['course'] = $row['course'];
                    $fixture['section'] = $row['section'];
                }
            }
            unset($fixture);
            file_put_contents($manifest, json_encode($fixtures, JSON_PRETTY_PRINT));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('This migration preserves existing records. Automatic rollback is not supported.');
    }
}
