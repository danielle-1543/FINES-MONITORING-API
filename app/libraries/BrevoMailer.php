<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

final class BrevoMailer
{
    public static function organization_code(array $organization): string
    {
        $aliases = [
            'COLLEGE OF ARTS AND SCIENCES' => 'CAS',
            'COLLEGE OF CRIMINAL JUSTICE EDUCATION' => 'CCJE',
            'CRIM' => 'CCJE',
            'COLLEGE OF BUSINESS AND MANAGEMENT' => 'CBM',
            'COLLEGE OF COMPUTER STUDIES' => 'CCS',
            'BTVTED' => 'BTVTED/BTLED',
            'BTLED' => 'BTVTED/BTLED',
            'UNIVERSITY STUDENT GOVERNMENT' => 'USG',
        ];
        $senders = require dirname(__DIR__) . '/config/brevo_senders.php';
        foreach (['organization_name', 'username', 'organization_course'] as $field) {
            $value = strtoupper(trim($organization[$field] ?? ''));
            $value = preg_replace('/\s*\/\s*/', '/', $value);
            $code = $aliases[$value] ?? $value;
            if ($code !== 'DEFAULT' && isset($senders[$code])) {
                return $code;
            }
        }
        throw new DomainException('No email sender is configured for this organization.', 503);
    }

    public static function sender(?array $organization = null): array
    {
        $senders = require dirname(__DIR__) . '/config/brevo_senders.php';
        $sender = $senders[$organization === null ? 'DEFAULT' : self::organization_code($organization)];
        if (!filter_var($sender['email'], FILTER_VALIDATE_EMAIL) || trim($sender['name']) === '') {
            throw new DomainException('The configured email sender is invalid.', 503);
        }
        return $sender;
    }

    public static function send(string $email, string $code): void
    {
        self::transactional([
            'sender' => self::sender(),
            'to' => [['email' => $email]],
            'subject' => 'Your Fines Monitoring verification code',
            'textContent' => "Your verification code is {$code}. It expires in 10 minutes. Do not share this code. If you did not request it, ignore this email.",
        ]);
    }

    public static function transactional(array $payload): void
    {
        $key = trim(getenv('BREVO_API_KEY') ?: '');
        if ($key === '' || !function_exists('curl_init')) {
            error_log('Brevo configuration missing: backend BREVO_API_KEY or PHP curl extension.');
            throw new DomainException('Email delivery is not configured. Contact the administrator.', 503);
        }
        if (!filter_var($payload['sender']['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('Invalid email sender.', 503);
        }
        $curl = curl_init('https://api.brevo.com/v3/smtp/email');
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($curl, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'api-key: ' . $key],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
        $result = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $response = is_string($result) ? json_decode($result, true) : null;
        if ($result !== false && $status >= 200 && $status < 300) {
            return;
        }
        // A repeated request with the same batch UUID was already accepted by Brevo.
        if (!empty($payload['headers']['idempotencyKey']) && ($response['code'] ?? '') === 'duplicate_parameter') {
            return;
        }
        error_log('Brevo transactional email failed (HTTP ' . $status . ').');
        throw new DomainException('Email delivery failed (HTTP ' . $status . ').', 503);
    }
}
