<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class XenditPaymentModel extends Model
{
    private function gateway(): XenditGateway
    {
        return $this->call->library('XenditGateway');
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
    }

    private function query()
    {
        return $this->db->table('xendit_payments x')
            ->select('x.claim_id, x.reference_id, x.session_id, x.payment_id, x.checkout_url, x.state, x.mode, p.amount, p.status, p.student_id, p.org_user_id, p.method')
            ->join('payment_claims p', 'p.id=x.claim_id')
            ->join('students s', 's.id=p.student_id');
    }

    public function checkout(int $userId, int $orgId, array $selection = []): array
    {
        $gateway = $this->gateway();
        $gateway->assert_configured();
        $existing = $this->query()->where('s.user_id', '=', $userId)
            ->where('p.org_user_id', '=', $orgId)->where('p.status', '=', 'PENDING')->get();
        if ($existing) return $this->refresh($existing);

        $reference = 'fms-test-' . bin2hex(random_bytes(16));
        // This reserves the server-calculated amount and its fine allocations
        // atomically. Concurrent/double clicks cannot reserve it a second time.
        $claimId = $this->call->model('PaymentModel')->submit($userId, array_intersect_key($selection, array_flip(['academic_period_id', 'amount'])) + [
            'fine_key' => 'ALL', 'organization_id' => $orgId,
        ], null, $reference);
        $attempt = $this->query()->where('x.claim_id', '=', $claimId)->get();
        try {
            $session = $gateway->create_session($reference, $attempt['amount']);
            $this->apply_session($attempt, $session);
        } catch (XenditGatewayException $error) {
            // A network timeout may have created a session. Never retry the
            // create blindly or release the balance while payment is possible.
            if ($error->definitive) $this->fail_creation($attempt);
            throw new DomainException($error->getMessage() . ' Reference: ' . $reference, 502);
        }
        return $this->view($this->query()->where('x.claim_id', '=', $claimId)->get());
    }

    public function status(int $userId, int $claimId): array
    {
        $attempt = $this->query()->where('s.user_id', '=', $userId)
            ->where('x.claim_id', '=', $claimId)->get();
        if (!$attempt) throw new DomainException('Xendit payment not found.', 404);
        return $this->refresh($attempt);
    }

    private function refresh(array $attempt): array
    {
        if ($attempt['status'] !== 'PENDING') return $this->view($attempt);
        if (!$attempt['session_id']) {
            throw new DomainException('Checkout creation is awaiting provider confirmation. Do not pay again. Ask the administrator to check Xendit using reference ' . $attempt['reference_id'] . '.', 409);
        }
        $session = $this->gateway()->get_session($attempt['session_id']);
        $this->apply_session($attempt, $session);
        return $this->view($this->query()->where('x.claim_id', '=', $attempt['claim_id'])->get());
    }

    public function webhook(array $event): void
    {
        if (!in_array($event['event'] ?? '', ['payment_session.completed', 'payment_session.expired'], true)) return;
        $data = $event['data'] ?? null;
        if (!is_array($data) || !is_string($data['reference_id'] ?? null) || !is_string($data['payment_session_id'] ?? null)) {
            throw new DomainException('Invalid payment session webhook.', 400);
        }
        $attempt = $this->query()->where('x.reference_id', '=', $data['reference_id'])->get();
        // Xendit's dashboard test payload and unrelated sessions have no claim.
        if (!$attempt) return;
        // Fetch authoritative server-to-server status, rather than trusting a
        // redirect, a client-provided amount, or the webhook's status field.
        $this->apply_session($attempt, $this->gateway()->get_session($data['payment_session_id']));
    }

    public static function validate_session(array $attempt, array $session): void
    {
        $amount = $session['amount'] ?? null;
        $cents = is_numeric($amount) ? (float) $amount * 100 : -1;
        if (!is_finite($cents) || abs($cents - round($cents)) > 0.00001
            || (int) round($cents) !== (int) round((float) $attempt['amount'] * 100)
            || ($session['reference_id'] ?? '') !== $attempt['reference_id']
            || ($session['currency'] ?? '') !== 'PHP' || ($session['country'] ?? '') !== 'PH'
            || ($session['session_type'] ?? '') !== 'PAY' || ($session['mode'] ?? '') !== 'PAYMENT_LINK'
            || ($session['capture_method'] ?? 'AUTOMATIC') !== 'AUTOMATIC'
            || !is_string($session['payment_session_id'] ?? null)
            || !preg_match('/^ps-[A-Za-z0-9-]{10,96}$/', $session['payment_session_id'])
            || (!empty($attempt['session_id']) && $attempt['session_id'] !== $session['payment_session_id'])
            || !in_array($session['status'] ?? '', ['ACTIVE', 'COMPLETED', 'EXPIRED', 'CANCELED'], true)) {
            throw new DomainException('Xendit session does not match the reserved payment.', 409);
        }
        if ($session['status'] === 'COMPLETED' && (!is_string($session['payment_id'] ?? null)
            || !preg_match('/^py-[A-Za-z0-9-]{10,96}$/', $session['payment_id']))) {
            throw new DomainException('Xendit has not provided a captured payment reference.', 409);
        }
    }

    private function apply_session(array $attempt, array $session): void
    {
        self::validate_session($attempt, $session);
        $url = $session['status'] === 'ACTIVE' ? XenditGateway::checkout_url($session['payment_link_url'] ?? null) : null;
        $this->db->transaction();
        try {
            // Use the same organization -> student -> claim lock order as the
            // rest of the payment ledger. Provider HTTP runs outside this lock.
            $this->db->raw('SELECT id FROM users WHERE id=? FOR UPDATE', [$attempt['org_user_id']]);
            $this->db->raw('SELECT id FROM students WHERE id=? FOR UPDATE', [$attempt['student_id']]);
            $claim = $this->db->raw('SELECT * FROM payment_claims WHERE id=? FOR UPDATE', [$attempt['claim_id']])->fetch(PDO::FETCH_ASSOC);
            $stored = $this->db->raw('SELECT * FROM xendit_payments WHERE claim_id=? FOR UPDATE', [$attempt['claim_id']])->fetch(PDO::FETCH_ASSOC);
            self::validate_session($stored + ['amount' => $claim['amount']], $session);
            if ($claim['method'] !== 'XENDIT_TEST' || $stored['mode'] !== 'test') throw new DomainException('Invalid test payment.', 409);
            if ($claim['status'] === 'CONFIRMED') {
                // Duplicate and out-of-order events cannot change a paid claim.
                $this->db->commit();
                return;
            }
            if ($claim['status'] !== 'PENDING') {
                if (in_array($session['status'], ['EXPIRED', 'CANCELED'], true)) {
                    $this->db->commit();
                    return;
                }
                throw new DomainException('This payment is already closed. Contact the administrator.', 409);
            }
            if ($session['status'] === 'COMPLETED') {
                $this->validate_allocations($claim);
                $this->db->table('payment_claims')->where('id', '=', $claim['id'])->update([
                    'status' => 'CONFIRMED', 'reviewed_at' => $this->now(),
                    'review_note' => 'TEST PAYMENT - verified Xendit simulation; no real funds collected.',
                    'reference_number' => $session['payment_id'],
                    'receipt_number' => 'TEST-FMS-' . str_pad((string) $claim['id'], 10, '0', STR_PAD_LEFT),
                ]);
            } elseif (in_array($session['status'], ['EXPIRED', 'CANCELED'], true)) {
                $this->db->table('payment_claims')->where('id', '=', $claim['id'])->update([
                    'status' => 'CANCELLED', 'reviewed_at' => $this->now(),
                    'review_note' => 'Xendit test checkout ' . strtolower($session['status']) . '; no payment confirmed.',
                ]);
            }
            $values = [
                'session_id' => $session['payment_session_id'], 'state' => $session['status'],
                'updated_at' => $this->now(),
            ];
            if ($url !== null) $values['checkout_url'] = $url;
            if ($session['status'] === 'COMPLETED') $values['payment_id'] = $session['payment_id'];
            $this->db->table('xendit_payments')->where('claim_id', '=', $claim['id'])->update($values);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    private function validate_allocations(array $claim): void
    {
        $fines = $this->call->model('PaymentModel')->fines((int) $claim['student_id']);
        $byKey = array_column($fines, null, 'fine_key');
        $items = $this->db->table('payment_claim_items')->where('claim_id', '=', $claim['id'])->get_all();
        $total = 0;
        foreach ($items as $item) {
            $fine = $byKey[$item['fine_key']] ?? null;
            $cents = (int) round((float) $item['amount'] * 100);
            if (!$fine || (int) $fine['org_user_id'] !== (int) $claim['org_user_id'] || $cents <= 0
                || $cents > (int) round((float) $fine['outstanding'] * 100)) {
                throw new DomainException('Payment allocation changed. Contact the administrator to reconcile this payment.', 409);
            }
            $total += $cents;
        }
        if ($total !== (int) round((float) $claim['amount'] * 100)) throw new DomainException('Payment allocation total does not match.', 409);
    }

    private function fail_creation(array $attempt): void
    {
        $this->db->transaction();
        try {
            $this->db->raw('SELECT id FROM users WHERE id=? FOR UPDATE', [$attempt['org_user_id']]);
            $this->db->raw('SELECT id FROM students WHERE id=? FOR UPDATE', [$attempt['student_id']]);
            $claim = $this->db->raw('SELECT status FROM payment_claims WHERE id=? FOR UPDATE', [$attempt['claim_id']])->fetch(PDO::FETCH_ASSOC);
            $stored = $this->db->raw('SELECT session_id FROM xendit_payments WHERE claim_id=? FOR UPDATE', [$attempt['claim_id']])->fetch(PDO::FETCH_ASSOC);
            if ($claim['status'] === 'PENDING' && !$stored['session_id']) {
                $this->db->table('payment_claims')->where('id', '=', $attempt['claim_id'])->update([
                    'status' => 'CANCELLED', 'reviewed_at' => $this->now(), 'review_note' => 'Xendit rejected checkout creation; no payment session created.',
                ]);
                $this->db->table('xendit_payments')->where('claim_id', '=', $attempt['claim_id'])->update([
                    'state' => 'FAILED', 'updated_at' => $this->now(),
                ]);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    private function view(array $attempt): array
    {
        return [
            'claim_id' => (int) $attempt['claim_id'], 'reference_id' => $attempt['reference_id'],
            'status' => $attempt['status'], 'session_status' => $attempt['state'],
            'checkout_url' => $attempt['status'] === 'PENDING' && $attempt['state'] === 'ACTIVE'
                ? XenditGateway::checkout_url($attempt['checkout_url']) : null,
            'mode' => 'test',
        ];
    }
}
