<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Arts_program_labels
{
    public const KEY = 'arts_program_labels';
    public const DESCRIPTION = 'AB ENGLISH and ABPSYCH labels';
    public const DEPENDENCIES = [];

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
            $rows = $pdo->query("SELECT id,course FROM students WHERE course IN ('BAEL','BAPSYCH') FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) {
                $dir = $root . '/runtime/program-migrations';
                if (!is_dir($dir)) {
                    mkdir($dir, 0700, true);
                }
                if (file_put_contents($dir . '/arts-labels-' . date('Ymd-His') . '.json', json_encode($rows, JSON_PRETTY_PRINT)) === false) {
                    throw new RuntimeException('Unable to back up labels.');
                }
            }
            $count = $pdo->exec("UPDATE students SET course=CASE course WHEN 'BAEL' THEN 'AB ENGLISH' WHEN 'BAPSYCH' THEN 'ABPSYCH' END WHERE course IN ('BAEL','BAPSYCH')");
            $pdo->commit();
            echo "Updated {$count} existing student program labels. Accounts and records preserved.\n";
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function down(): void
    {
        throw new RuntimeException('This migration preserves existing records. Automatic rollback is not supported.');
    }
}
