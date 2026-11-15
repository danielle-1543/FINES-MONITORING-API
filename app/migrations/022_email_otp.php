<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
class Email_otp
{
    public const KEY = 'email_otp';
    public const DESCRIPTION = 'Email verification and single-use transactional email OTPs';
    public const DEPENDENCIES = ['account_settings'];
    public function up(?PDO $pdo = null, ?string $root = null): void
    {
        if (!$pdo) {
            require_once dirname(__DIR__).'/libraries/FinesMigrationRunner.php';
            FinesMigrationRunner::apply(self::KEY); return;
        }
        if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'email_verified_at'")->fetch()) {
            $pdo->exec('ALTER TABLE users ADD email_verified_at DATETIME NULL');
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS email_otps (
            id CHAR(64) PRIMARY KEY, subject_key CHAR(64) NOT NULL UNIQUE,
            user_id BIGINT UNSIGNED NULL, email VARCHAR(150) NOT NULL, purpose VARCHAR(30) NOT NULL,
            code_hash VARCHAR(255) NOT NULL, expires_at BIGINT NOT NULL,
            created_at BIGINT NOT NULL, attempts INT NOT NULL DEFAULT 0,
            INDEX otp_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
