<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Program_catalog
{
    public const KEY = 'program_catalog';
    public const DESCRIPTION = 'Database-backed academic programs and college mappings';
    public const DEPENDENCIES = [];

    public function up(?PDO $pdo = null, ?string $root = null): void
    {
        if ($pdo === null) {
            require_once dirname(__DIR__) . '/libraries/FinesMigrationRunner.php';
            FinesMigrationRunner::apply(self::KEY);
            return;
        }

        $root ??= dirname(__DIR__, 2);
        $pdo->exec('CREATE TABLE IF NOT EXISTS academic_programs (code VARCHAR(100) PRIMARY KEY, name VARCHAR(200) NOT NULL, selectable TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $pdo->exec('CREATE TABLE IF NOT EXISTS academic_colleges (code VARCHAR(100) PRIMARY KEY, selectable TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $pdo->exec('CREATE TABLE IF NOT EXISTS academic_college_programs (college_code VARCHAR(100) NOT NULL, program_code VARCHAR(100) NOT NULL, PRIMARY KEY(college_code,program_code), FOREIGN KEY(college_code) REFERENCES academic_colleges(code), FOREIGN KEY(program_code) REFERENCES academic_programs(code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $seed = [
            'student_programs' => [
                'AB ENGLISH' => 'Bachelor of Arts in English Language',
                'ABPSYCH' => 'Bachelor of Arts in Psychology',
                'BSHM' => 'Bachelor of Science in Hospitality Management',
                'BSTM' => 'Bachelor of Science in Tourism Management',
                'BSIT' => 'Bachelor of Science in Information Technology',
                'BSCRIM' => 'Bachelor of Science in Criminology',
                'BSED' => 'Bachelor of Secondary Education',
                'BTVTED' => 'Bachelor of Technical-Vocational Teacher Education',
                'BTLED' => 'Bachelor of Technology and Livelihood Education',
            ],
            'organization_colleges' => [
                'CAS',
                'CBM',
                'CCS',
                'CCJE',
                'CTE',
            ],
            'organization_course_groups' => [
                'CCS' => [
                    'BSIT',
                    'CCS',
                ],
                'CAS' => [
                    'AB ENGLISH',
                    'ABPSYCH',
                    'CAS',
                ],
                'CBM' => [
                    'BSHM',
                    'BSTM',
                ],
                'CCJE' => [
                    'BSCRIM',
                    'CRIM',
                ],
                'CTE' => [
                    'BSED',
                    'BTVTED',
                    'BTLED',
                ],
                'BSED' => [
                    'BSED',
                ],
                'BTVTED' => [
                    'BTVTED',
                ],
                'BTLED' => [
                    'BTLED',
                ],
                'CRIM' => [
                    'CRIM',
                ],
            ],
        ];
        $programs = $seed['student_programs'];
        foreach ($seed['organization_course_groups'] as $codes) {
            foreach ($codes as $code) {
                $programs[$code] = $programs[$code] ?? $code;
            }
        }
        $pdo->beginTransaction();
        try {
            $program = $pdo->prepare('INSERT INTO academic_programs(code,name,selectable) VALUES(?,?,?) ON DUPLICATE KEY UPDATE code=VALUES(code)');
            foreach ($programs as $code => $name) {
                $program->execute([
                    $code,
                    $name,
                    (int) isset($seed['student_programs'][$code])
                ]);
            }
            $college = $pdo->prepare('INSERT INTO academic_colleges(code,selectable) VALUES(?,?) ON DUPLICATE KEY UPDATE code=VALUES(code)');
            $mapping = $pdo->prepare('INSERT INTO academic_college_programs(college_code,program_code) VALUES(?,?) ON DUPLICATE KEY UPDATE program_code=VALUES(program_code)');
            foreach ($seed['organization_course_groups'] as $code => $programCodes) {
                $college->execute([
                    $code,
                    (int) in_array($code, $seed['organization_colleges'], true)
                ]);
                foreach ($programCodes as $programCode) {
                    $mapping->execute([
                        $code,
                        $programCode
                    ]);
                }
            }
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        echo "Academic program catalog ready. Existing organization selections preserved.\n";
    }

    public function down(): void
    {
        throw new RuntimeException('This migration preserves existing records. Automatic rollback is not supported.');
    }
}
