<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class XenditWebhookController extends Controller
{
    public function receive(): void
    {
        $this->call->library('api');
        $this->api->require_method('POST');
        header('Cache-Control: no-store');
        try {
            // Provider authentication replaces the student JWT on this route.
            $gateway = $this->call->library('XenditGateway');
            $gateway->verify_webhook_token($_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? '');
            $raw = file_get_contents('php://input', false, null, 0, 65537);
            if ($raw === false || strlen($raw) > 65536) throw new DomainException('Webhook body is too large.', 413);
            $event = json_decode($raw, true);
            if (!is_array($event)) throw new DomainException('Invalid webhook JSON.', 400);
            $this->call->model('XenditPaymentModel')->webhook($event);
            $this->api->respond(['success' => true]);
        } catch (DomainException $error) {
            $this->api->respond_error($error->getMessage(), $error->getCode() ?: 400);
        } catch (Throwable $error) {
            error_log('Xendit webhook processing failed: ' . get_class($error));
            // Non-2xx makes Xendit retry; do not acknowledge an uncommitted write.
            $this->api->respond_error('Unable to process the webhook. Please retry.', 503);
        }
    }
}
