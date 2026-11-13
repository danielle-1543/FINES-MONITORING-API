<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

function _is_api_request(): bool
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    return (bool) preg_match('~(?:^|/)api(?:/|$)~', $path);
}

function _api_error_response(int $status, string $detail = ''): void
{
    $reference = bin2hex(random_bytes(8));
    error_log('API failure [' . $reference . '] HTTP ' . $status . ': ' . $detail);
    // Never send partial output, HTML, SQL, stack traces, or credentials to clients.
    while (ob_get_level() > 0) {
        if (!@ob_end_clean()) break;
    }
    http_response_code($status);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $message = $status === 404 ? 'The requested API route was not found.'
        : 'The server could not complete the request. Please contact the administrator with the reference ID.';
    echo json_encode([
        'success' => false,
        'error' => $message,
        'message' => $message,
        'status' => $status,
        'request_id' => $reference,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}
