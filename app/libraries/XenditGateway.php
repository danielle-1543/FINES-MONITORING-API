<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class XenditGatewayException extends RuntimeException
{
    public bool $definitive;

    public function __construct(string $message, bool $definitive = false)
    {
        parent::__construct($message);
        $this->definitive = $definitive;
    }
}

class XenditGateway
{
    public function assert_configured(): void
    {
        if (strtolower(trim(getenv('XENDIT_MODE') ?: '')) !== 'test') {
            throw new DomainException('Xendit is available in test mode only. Set XENDIT_MODE=test on the API server.', 503);
        }
        if (!str_starts_with(trim(getenv('XENDIT_SECRET_API_KEY') ?: ''), 'xnd_development_')
            || trim(getenv('XENDIT_WEBHOOK_TOKEN') ?: '') === '') {
            throw new DomainException('Configure a Xendit secret test API key and webhook verification token on the API server.', 503);
        }
        if (!function_exists('curl_init')) {
            throw new DomainException('The API server requires the PHP cURL extension for Xendit.', 503);
        }
        $this->frontend_url();
    }

    public function verify_webhook_token(string $provided): void
    {
        $expected = trim(getenv('XENDIT_WEBHOOK_TOKEN') ?: '');
        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            throw new DomainException('Invalid webhook verification token.', 401);
        }
    }

    public static function checkout_url($value): string
    {
        $parts = is_string($value) ? parse_url($value) : false;
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || !in_array(strtolower($parts['host'] ?? ''), [
                'dev.xen.to', 'xen.to', 'checkout-staging.xendit.co', 'checkout.xendit.co'
            ], true)) {
            throw new XenditGatewayException('Xendit did not return a valid hosted checkout URL.');
        }
        return $value;
    }

    public function create_session(string $reference, string $amount): array
    {
        // Return navigation is never used as proof that the payment succeeded.
        $frontend = $this->frontend_url();
        return $this->request('POST', '/sessions', [
            'reference_id' => $reference,
            'session_type' => 'PAY',
            'mode' => 'PAYMENT_LINK',
            'capture_method' => 'AUTOMATIC',
            'allow_save_payment_method' => 'DISABLED',
            'amount' => (float) $amount,
            'currency' => 'PHP',
            'country' => 'PH',
            'description' => 'TEST PAYMENT - MinSU organization fines',
            'success_return_url' => $frontend . '/?xendit=return#payments',
            'cancel_return_url' => $frontend . '/?xendit=cancel#payments',
        ]);
    }

    private function frontend_url(): string
    {
        $value = rtrim(getenv('FRONTEND_URL') ?: 'https://fines-monitoring-react-1.onrender.com', '/');
        if (!filter_var($value, FILTER_VALIDATE_URL) || parse_url($value, PHP_URL_SCHEME) !== 'https'
            || parse_url($value, PHP_URL_USER) !== null || parse_url($value, PHP_URL_QUERY) !== null
            || parse_url($value, PHP_URL_FRAGMENT) !== null) {
            throw new DomainException('FRONTEND_URL must be the HTTPS frontend website without credentials, query, or fragment.', 503);
        }
        return $value;
    }

    public function get_session(string $sessionId): array
    {
        if (!preg_match('/^ps-[A-Za-z0-9-]{10,96}$/', $sessionId)) {
            throw new DomainException('Invalid Xendit session ID.', 422);
        }
        return $this->request('GET', '/sessions/' . rawurlencode($sessionId));
    }

    protected function request(string $method, string $path, ?array $payload = null): array
    {
        $this->assert_configured();
        $curl = curl_init('https://api.xendit.co' . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERPWD => trim(getenv('XENDIT_SECRET_API_KEY')) . ':',
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($payload !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($body === false) {
            throw new XenditGatewayException('Xendit did not respond. Check the pending checkout before trying again.');
        }
        $result = json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($result)) {
            $code = is_string($result['error_code'] ?? null) ? $result['error_code'] : 'INVALID_RESPONSE';
            error_log('Xendit request failed: HTTP ' . $status . ' ' . preg_replace('/[^A-Z0-9_]/i', '', $code));
            throw new XenditGatewayException(
                'Xendit could not process checkout (HTTP ' . $status . '). Check the API key permissions and Xendit dashboard.',
                in_array($status, [400, 401, 403, 404, 422], true)
            );
        }
        return $result;
    }
}
