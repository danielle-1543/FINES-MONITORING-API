<?php
// In-memory fixtures only. No credentials, remote API, or real payment database.
define('PREVENT_DIRECT_ACCESS', true);
function config_item($key) { return ['timestamps' => false, 'soft_delete' => false][$key] ?? null; }
function load_class($name, $dir = '') { return new class {
    public function show_database_error($message, $sql = '', $bindings = [], $error = null) { throw $error ?: new RuntimeException($message); }
}; }
require dirname(__DIR__) . '/scheme/database/Database.php';
require dirname(__DIR__) . '/scheme/kernel/Model.php';
function lava_instance() { return $GLOBALS['xenditTestLava']; }
require dirname(__DIR__) . '/app/libraries/XenditGateway.php';
require dirname(__DIR__) . '/app/models/PaymentModel.php';
require dirname(__DIR__) . '/app/models/XenditPaymentModel.php';
require dirname(__DIR__) . '/app/models/PaymentPeriodModel.php';

class FixtureDatabase extends Database {
    public function raw($query, $args = []) {
        return parent::raw(str_replace([' FOR UPDATE', 'INSERT IGNORE'], ['', 'INSERT OR IGNORE'], $query), $args);
    }
}
class FixturePayments extends PaymentModel {
    public PDO $pdo;
    public function fines(int $studentId): array {
        $rows = [];
        foreach (['scan-1' => 60, 'violation-2' => 40] as $key => $amount) {
            $query = $this->pdo->prepare("SELECT p.status, SUM(i.amount) amount FROM payment_claims p JOIN payment_claim_items i ON i.claim_id=p.id WHERE p.student_id=? AND i.fine_key=? GROUP BY p.status");
            $query->execute([$studentId, $key]); $paid = 0; $pending = 0;
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $claim) {
                if ($claim['status'] === 'CONFIRMED') $paid += $claim['amount'];
                if ($claim['status'] === 'PENDING') $pending += $claim['amount'];
            }
            $rows[] = ['fine_key' => $key, 'org_user_id' => 10, 'payment_window' => 'OPEN',
                'fine_amount' => number_format($amount, 2, '.', ''), 'paid_amount' => number_format($paid, 2, '.', ''),
                'cap_adjustment' => '0.00', 'discount_amount' => '0.00',
                'pending_amount' => number_format($pending, 2, '.', ''),
                'outstanding' => number_format($amount - $paid, 2, '.', ''),
                'available_to_claim' => number_format($amount - $paid - $pending, 2, '.', '')];
        }
        return $rows;
    }
}
class FixtureGateway extends XenditGateway {
    public array $sessions = [];
    public int $creates = 0;
    public ?bool $fail = null;
    public function assert_configured(): void {}
    public function create_session(string $reference, string $amount): array {
        $this->creates++;
        if ($this->fail !== null) throw new XenditGatewayException('Simulated provider failure.', $this->fail);
        return $this->sessions['ps-' . str_pad((string) $this->creates, 24, '0', STR_PAD_LEFT)] = [
            'payment_session_id' => 'ps-' . str_pad((string) $this->creates, 24, '0', STR_PAD_LEFT),
            'reference_id' => $reference, 'amount' => (float) $amount, 'currency' => 'PHP', 'country' => 'PH',
            'session_type' => 'PAY', 'mode' => 'PAYMENT_LINK', 'status' => 'ACTIVE',
            'payment_link_url' => 'https://dev.xen.to/demo', 'capture_method' => 'AUTOMATIC',
        ];
    }
    public function get_session(string $sessionId): array { return $this->sessions[$sessionId]; }
}
function check($value, string $label): void { if (!$value) throw new RuntimeException('FAIL: ' . $label); echo 'PASS: ' . $label . PHP_EOL; }
function denied(callable $operation, int $status, string $label): void {
    try { $operation(); } catch (DomainException $error) { check($error->getCode() === $status, $label); return; }
    throw new RuntimeException('FAIL: ' . $label . ' was accepted');
}
function fixture(): array {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT,organization_name TEXT); INSERT INTO users VALUES(1,'STUDENT','ACTIVE',NULL),(10,'ORGANIZATION','ACTIVE','CCS'),(20,'ORGANIZATION','ACTIVE','USG'),(30,'ORGANIZATION','ACTIVE','Business'),(40,'ORGANIZATION','INACTIVE','Inactive');
        CREATE TABLE students(id INTEGER PRIMARY KEY,user_id INTEGER,course TEXT,account_status TEXT,activation_status TEXT,full_name TEXT,student_number TEXT); INSERT INTO students VALUES(2,1,'BSIT','ACTIVE','ACTIVATED','Demo Student','DEMO-1');
        CREATE TABLE payment_claims(id INTEGER PRIMARY KEY AUTOINCREMENT,student_id INTEGER,org_user_id INTEGER,fine_key TEXT,amount TEXT,method TEXT,reference_number TEXT,proof_file TEXT,proof_mime TEXT,submitted_at TEXT,status TEXT,reviewed_at TEXT,review_note TEXT,reviewed_by INTEGER,receipt_number TEXT UNIQUE);
        ALTER TABLE payment_claims ADD academic_period_id INTEGER;
        CREATE TABLE payment_claim_items(claim_id INTEGER,fine_key TEXT,amount TEXT,PRIMARY KEY(claim_id,fine_key));
        CREATE TABLE xendit_payments(claim_id INTEGER PRIMARY KEY,reference_id TEXT UNIQUE,session_id TEXT UNIQUE,payment_id TEXT UNIQUE,checkout_url TEXT,state TEXT,mode TEXT,created_at TEXT,updated_at TEXT);");
    $db = (new ReflectionClass(FixtureDatabase::class))->newInstanceWithoutConstructor();
    foreach (['db' => $pdo, 'driver' => 'sqlite', 'db_prefix' => ''] as $key => $value) (new ReflectionProperty(Database::class, $key))->setValue($db, $value);
    $payments = new FixturePayments(); $payments->pdo = $pdo;
    $gateway = new FixtureGateway();
    $model = new XenditPaymentModel();
    $call = new class($payments, $gateway) {
        public function __construct(public $payments, public $gateway) {}
        public function model($name) {
            if ($name === 'AcademicPeriodModel') return new class {
                public function periods() { return []; }
                public function current() { return null; }
            };
            if ($name === 'PaymentModel') return $this->payments;
            if ($name === 'PaymentPeriodModel') return new class {
                public function periods($id) { return [['id' => $id, 'label' => 'Demo', 'opens_at' => '2000-01-01 00:00:00', 'closes_at' => '2099-01-01 00:00:00']]; }
            };
            return new class { public function organization($id) { return ['allowed_courses' => $id === 30 ? ['BSBA'] : null]; } };
        }
        public function library($name) { return $this->gateway; }
    };
    $GLOBALS['xenditTestLava'] = (object) ['db' => $db, 'call' => $call];
    return [$pdo, $payments, $gateway, $model];
}

[$pdo, $payments, $gateway, $model] = fixture();
$schedule = ['id' => 50, 'org_user_id' => 10, 'label' => 'Demo Semester', 'semester_start' => '2026-01-01',
    'semester_end' => '2026-12-31', 'opens_at' => '2026-11-13 08:00:00', 'closes_at' => '2026-11-20 17:00:00', 'cap_amount' => null];
$scheduledFine = ['org_user_id' => 10, 'fine_key' => 'test-fine', 'recorded_at' => '2026-11-01 08:00:00',
    'fine_amount' => '30.00', 'paid_amount' => '0.00', 'pending_amount' => '0.00'];
$calculated = PaymentPeriodModel::calculate([$scheduledFine], [$schedule], '2026-11-13 08:00:00');
check($calculated[0]['payment_period_id'] === 50 && $calculated[0]['payment_window'] === 'OPEN', 'schedule metadata links each fine to its payment period at opening boundary');
check(PaymentPeriodModel::calculate([$scheduledFine], [$schedule], '2026-11-20 17:00:00')[0]['payment_window'] === 'CLOSED', 'schedule is closed at its closing boundary');
$overview = $payments->overview(1);
check(array_column($overview['organizations'], 'id') === [10, 20], 'payment choices include only active organizations covering student program');
check($overview['organizations'][0]['schedules'][0]['payment_window'] === 'OPEN', 'student overview includes payment schedules and window status');
$pdo->exec("INSERT INTO payment_claims(student_id,org_user_id,fine_key,amount,method,status) VALUES(2,10,'removed-fine','1.00','CASH','PENDING')");
denied(fn () => $payments->submit(1, ['fine_key' => 'ALL', 'method' => 'CASH', 'organization_id' => 10, 'amount' => '100.00']), 409, 'pending claim on a historical fine still blocks duplicate organization payment');
[$pdo, $payments, $gateway, $model] = fixture();
$checkout = $model->checkout(1, 10);
denied(fn () => $payments->submit(1, ['fine_key' => 'ALL', 'method' => 'XENDIT_TEST', 'organization_id' => 10, 'amount' => '0.01']), 422, 'manual endpoint cannot select internal Xendit method');
check((float) $pdo->query('SELECT amount FROM payment_claims')->fetchColumn() === 100.0, 'server calculates full organization balance');
check((int) $pdo->query('SELECT COUNT(*) FROM payment_claim_items')->fetchColumn() === 2, 'all fine allocations reserved');
$again = $model->checkout(1, 10);
check($again['claim_id'] === $checkout['claim_id'] && $gateway->creates === 1, 'repeated checkout resumes one session');
denied(fn () => $model->status(999, $checkout['claim_id']), 404, 'other student cannot inspect session');
denied(fn () => $payments->cancel(1, $checkout['claim_id']), 409, 'student cannot locally cancel provider payment');
denied(fn () => $payments->review(10, $checkout['claim_id'], 'CONFIRMED', ''), 409, 'organization cannot manually confirm provider payment');
$sessionId = array_key_first($gateway->sessions);
$gateway->sessions[$sessionId]['status'] = 'COMPLETED';
$gateway->sessions[$sessionId]['payment_id'] = 'py-123456789012345678901234';
$gateway->sessions[$sessionId]['amount'] = 0.01;
denied(fn () => $model->status(1, $checkout['claim_id']), 409, 'amount mismatch cannot confirm claim');
$gateway->sessions[$sessionId]['amount'] = 100;
foreach (['currency' => 'USD', 'country' => 'ID', 'session_type' => 'SAVE', 'capture_method' => 'MANUAL', 'reference_id' => 'wrong-reference', 'payment_id' => ''] as $field => $wrong) {
    $valid = $gateway->sessions[$sessionId][$field];
    $gateway->sessions[$sessionId][$field] = $wrong;
    denied(fn () => $model->status(1, $checkout['claim_id']), 409, 'reject mismatched ' . $field);
    $gateway->sessions[$sessionId][$field] = $valid;
}
$event = ['event' => 'payment_session.completed', 'data' => ['reference_id' => $checkout['reference_id'], 'payment_session_id' => $sessionId]];
$model->webhook($event); $model->webhook($event);
$claim = $pdo->query('SELECT * FROM payment_claims')->fetch();
check($claim['status'] === 'CONFIRMED' && str_starts_with($claim['receipt_number'], 'TEST-FMS-'), 'verified payment confirms with TEST receipt');
check((int) $pdo->query('SELECT COUNT(*) FROM payment_claims')->fetchColumn() === 1, 'duplicate webhook does not create duplicate payment');
$gateway->sessions[$sessionId]['status'] = 'EXPIRED'; $model->webhook($event);
check($pdo->query('SELECT status FROM payment_claims')->fetchColumn() === 'CONFIRMED', 'out-of-order expiry cannot reverse confirmed payment');

[$pdo, $payments, $gateway, $model] = fixture();
$checkout = $model->checkout(1, 10); $sessionId = array_key_first($gateway->sessions);
$gateway->sessions[$sessionId]['status'] = 'EXPIRED';
check($model->status(1, $checkout['claim_id'])['status'] === 'CANCELLED', 'provider expiry closes pending reservation');
check($model->checkout(1, 10)['claim_id'] !== $checkout['claim_id'], 'expired checkout releases balance for a new attempt');
foreach ([true, false] as $definitive) {
    [$pdo, $payments, $gateway, $model] = fixture(); $gateway->fail = $definitive;
    denied(fn () => $model->checkout(1, 10), 502, 'provider failure reported');
    check($pdo->query('SELECT status FROM payment_claims')->fetchColumn() === ($definitive ? 'CANCELLED' : 'PENDING'), $definitive ? 'definitive rejection releases reservation' : 'uncertain timeout preserves reservation');
    if (!$definitive) {
        denied(fn () => $model->checkout(1, 10), 409, 'uncertain checkout is not blindly recreated');
        check($gateway->creates === 1, 'no duplicate provider create after timeout');
    }
}
$gateway = new XenditGateway();
putenv('XENDIT_WEBHOOK_TOKEN=fixture-secret');
denied(fn () => $gateway->verify_webhook_token('bad'), 401, 'invalid webhook token rejected');
$gateway->verify_webhook_token('fixture-secret');
putenv('XENDIT_MODE=live');
denied(fn () => $gateway->assert_configured(), 503, 'live mode disabled');
foreach (['https://evil.example/', 'javascript:alert(1)', 'https://dev.xen.to.evil.example/', 'https://user@dev.xen.to/'] as $url) {
    try { XenditGateway::checkout_url($url); throw new RuntimeException('Unsafe checkout URL accepted'); }
    catch (XenditGatewayException $expected) {}
}
echo "PASS: unsafe checkout links rejected; no real API calls or database writes.\n";
