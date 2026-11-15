<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
final class BrevoMailer
{
    public static function send(string $email, string $code): void
    {
        $key = getenv('BREVO_API_KEY') ?: '';
        $sender = getenv('BREVO_SENDER_EMAIL') ?: '';
        if (!$key || !filter_var($sender, FILTER_VALIDATE_EMAIL) || !function_exists('curl_init')) {
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
                'sender' => ['email' => $sender, 'name' => getenv('BREVO_SENDER_NAME') ?: 'Fines Monitoring'],
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
}
