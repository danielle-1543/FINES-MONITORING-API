<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

final class BrevoMailer
{
    public static function send(string $email, string $code): void
    {
        $key = trim(getenv('BREVO_API_KEY') ?: '');
        $sender = trim(getenv('BREVO_SENDER_EMAIL') ?: 'minsufinesmonitoring@gmail.com');
        $missing = [];
        if ($key === '') $missing[] = 'BREVO_API_KEY';
        if (!filter_var($sender, FILTER_VALIDATE_EMAIL)) $missing[] = 'valid BREVO_SENDER_EMAIL';
        if (!function_exists('curl_init')) $missing[] = 'PHP curl extension';
        if ($missing) {
            error_log('Brevo configuration missing: '.implode(', ', $missing).'. Configure the PHP API service environment.');
            throw new DomainException('Email delivery is not configured. Contact the administrator.', 503);
        }
        $curl = curl_init('https://api.brevo.com/v3/smtp/email');
        // WAMP may lack a PEM bundle. Use Windows' trusted roots while retaining TLS verification.
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($curl, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'api-key: '.$key],
            CURLOPT_POSTFIELDS => json_encode([
                'sender' => ['email' => $sender, 'name' => getenv('BREVO_SENDER_NAME') ?: 'Fines Monitoring System'],
                'to' => [['email' => $email]], 'subject' => 'Your Fines Monitoring verification code',
                'textContent' => "Your verification code is {$code}. It expires in 10 minutes. Do not share this code. If you did not request it, ignore this email."
            ], JSON_THROW_ON_ERROR)]);
        $result = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($result === false || $status < 200 || $status >= 300) {
            error_log('Brevo transactional email failed (HTTP '.(int)$status.').');
            throw new DomainException('Unable to send verification email. Try again later.', 503);
        }
    }

    public static function configured_senders(): array
    {
        $senders = require dirname(__DIR__) . '/config/email_senders.php';
        return is_array($senders) ? $senders : [];
    }

    protected function request(string $method, string $path, ?array $payload = null): array
    {
        $key = trim(getenv('BREVO_API_KEY') ?: '');
        if ($key === '' || !function_exists('curl_init')) {
            throw new DomainException('Email delivery is not configured. Contact the administrator.', 503);
        }
        $curl = curl_init('https://api.brevo.com/v3/' . $path);
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($curl, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
        $headers = ['Content-Type: application/json', 'Accept: application/json', 'api-key: ' . $key];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload ?? [], JSON_THROW_ON_ERROR);
        } else {
            $options[CURLOPT_HTTPGET] = true;
        }
        curl_setopt_array($curl, $options);
        $result = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($result === false || $status < 200 || $status >= 300) {
            error_log('Brevo notification request failed (HTTP ' . $status . ').');
            throw new DomainException('Unable to send email notification. Try again later.', 503);
        }
        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            error_log('Brevo notification response was not valid JSON.');
            throw new DomainException('Unable to send email notification. Try again later.', 503);
        }
        return $decoded;
    }

    public function sender_statuses(): array
    {
        $configured = self::configured_senders();
        $active = [];
        $response = $this->request('GET', 'senders');
        foreach (($response['senders'] ?? []) as $sender) {
            if (!is_array($sender)) continue;
            $email = strtolower(trim((string) ($sender['email'] ?? '')));
            if ($email !== '' && !empty($sender['active'])) $active[$email] = true;
        }
        $statuses = [];
        foreach ($configured as $code => $sender) {
            $statuses[$code] = $sender + [
                'code' => $code,
                'verified' => isset($active[strtolower(trim((string) $sender['email']))]),
            ];
        }
        return $statuses;
    }

    public function verified_sender(string $code): array
    {
        $configured = self::configured_senders();
        if (!isset($configured[$code])) {
            throw new DomainException('Choose an assigned system sender.', 422);
        }
        $sender = $configured[$code];
        $statuses = $this->sender_statuses();
        if (empty($statuses[$code]['verified'])) {
            throw new DomainException('The assigned sender email is not active and verified in Brevo. Contact the administrator.', 409);
        }
        return ['email' => $sender['email'], 'name' => $sender['name']];
    }

    public function send_notification(string $code, string $email, string $subject, string $message): string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('The student has no valid registered email address.', 422);
        }
        $sender = $this->verified_sender($code);
        $response = $this->request('POST', 'smtp/email', [
            'sender' => $sender,
            'to' => [['email' => $email]],
            'subject' => $subject,
            'textContent' => $message,
        ]);
        $messageId = $response['messageId'] ?? null;
        if (!is_string($messageId) || $messageId === '') {
            throw new DomainException('Unable to send email notification. Try again later.', 503);
        }
        return $messageId;
    }

    public function send_system_notification(string $email, string $subject, string $message): string
    {
        return $this->send_notification('DEFAULT', $email, $subject, $message);
    }
}

