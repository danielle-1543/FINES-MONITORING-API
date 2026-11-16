<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Organization_email_senders
{
    public const KEY = 'organization_email_senders';
    public const DESCRIPTION = 'Organization Brevo sender assignments';
    public const DEPENDENCIES = [];

    public function up(?PDO $pdo = null, ?string $root = null): void
    {
        if ($pdo === null) {
            require_once dirname(__DIR__) . '/libraries/FinesMigrationRunner.php';
            FinesMigrationRunner::apply(self::KEY);
            return;
        }

        $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $query->execute(['users', 'organization_email_sender_code']);
        if (!$query->fetchColumn()) {
            $pdo->exec('ALTER TABLE users ADD COLUMN organization_email_sender_code VARCHAR(20) NULL');
        }

        $senders = require dirname(__DIR__) . '/config/email_senders.php';
        $byEmail = [];
        foreach ($senders as $code => $sender) {
            if ($code === 'DEFAULT') continue;
            $byEmail[strtolower($sender['email'])] = $code;
        }

        $orgs = $pdo->query("SELECT id, username, organization_name, email, organization_email_sender_code FROM users WHERE role = 'ORGANIZATION'")->fetchAll(PDO::FETCH_ASSOC);
        $update = $pdo->prepare('UPDATE users SET organization_email_sender_code = ? WHERE id = ? AND role = \'ORGANIZATION\' AND (organization_email_sender_code IS NULL OR organization_email_sender_code = \'\')');
        foreach ($orgs as $org) {
            if (!empty($org['organization_email_sender_code'])) continue;
            $email = strtolower(trim((string) ($org['email'] ?? '')));
            $username = strtolower(trim((string) ($org['username'] ?? '')));
            $name = strtoupper(trim((string) ($org['organization_name'] ?? '')));
            $code = $byEmail[$email] ?? null;
            if ($code === null) {
                if ($username === 'usg' || $name === 'USG' || $name === 'UNIVERSITY STUDENT GOVERNMENT') $code = 'USG';
                elseif ($username === 'cas' || $name === 'CAS' || strpos($name, 'COLLEGE OF ARTS') !== false) $code = 'CAS';
                elseif ($username === 'ccje' || $name === 'CCJE' || strpos($name, 'CRIMINAL JUSTICE') !== false) $code = 'CCJE';
                elseif ($username === 'cbm' || $name === 'CBM' || strpos($name, 'BUSINESS MANAGEMENT') !== false) $code = 'CBM';
                elseif ($username === 'ccs' || $name === 'CCS' || strpos($name, 'COMPUTER STUDIES') !== false) $code = 'CCS';
                elseif ($username === 'bsed' || $name === 'BSED' || strpos($name, 'SECONDARY EDUCATION') !== false) $code = 'BSED';
                elseif (in_array($username, ['btvted', 'btled', 'btvted_btled'], true) || in_array($name, ['BTVTED', 'BTLED', 'BTVTED/BTLED'], true)
                    || strpos($name, 'TECHNICAL-VOCATIONAL') !== false || strpos($name, 'TECHNOLOGY AND LIVELIHOOD') !== false) $code = 'BTVTED_BTLED';
            }
            if ($code !== null && isset($senders[$code])) {
                $update->execute([$code, $org['id']]);
            }
        }

        echo "Organization email sender assignments ready. Sender verification is enforced at send time via Brevo.\n";
    }

    public function down(): void
    {
        throw new RuntimeException('This migration preserves existing records. Automatic rollback is not supported.');
    }
}
