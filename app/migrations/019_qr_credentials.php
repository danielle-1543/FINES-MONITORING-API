<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
class Qr_credentials
{
    public const KEY = 'qr_credentials';
    public const DESCRIPTION = 'QR history and section identifier standardization';
    public const DEPENDENCIES = [];
    public function up(?PDO $pdo = null, ?string $root = null): void
    {
        if ($pdo === null) {
            require_once dirname(__DIR__) . '/libraries/FinesMigrationRunner.php';
            FinesMigrationRunner::apply(self::KEY);
            return;
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS student_qr_credentials (
            token_hash CHAR(64) PRIMARY KEY, student_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(10) NOT NULL, generated_at DATETIME NULL,
            changed_at DATETIME NULL, changed_by BIGINT UNSIGNED NULL,
            INDEX student_qr_history (student_id, generated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $pdo->exec("INSERT IGNORE INTO student_qr_credentials (token_hash,student_id,status,generated_at)
            SELECT SHA2(qr_token,256),id,qr_token_status,qr_token_generated_at FROM students WHERE qr_token IS NOT NULL AND qr_token<>''");
        $pdo->exec('CREATE TABLE IF NOT EXISTS section_format_changes (
            student_id BIGINT UNSIGNED PRIMARY KEY, original_section VARCHAR(50), section VARCHAR(50), changed_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $rows = $pdo->query('SELECT id,section FROM students')->fetchAll(PDO::FETCH_ASSOC);
        $save = $pdo->prepare('INSERT IGNORE INTO section_format_changes VALUES (?,?,?,?)');
        $update = $pdo->prepare('UPDATE students SET section=? WHERE id=?');
        $pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                if (!preg_match('/^(?:[1-9][0-9]*)?([A-Z]+[1-9][0-9]*)$/', strtoupper(trim($row['section'])), $match)) continue;
                if ($match[1] === $row['section']) continue;
                $save->execute([$row['id'], $row['section'], $match[1], (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s')]);
                $update->execute([$match[1], $row['id']]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }
    public function down(): void
    {
        throw new RuntimeException('QR and section history must be preserved.');
    }
}
