<?php
// Isolated fixtures: no application database connection and no external email.
define('PREVENT_DIRECT_ACCESS', true);
function config_item($key) { return ['timestamps' => false, 'soft_delete' => false][$key] ?? null; }
function load_class($name, $directory = '') {
    return new class {
        public function show_database_error($message, $sql = '', $bindings = [], $error = null) {
            throw $error ?: new RuntimeException($message);
        }
    };
}
function lava_instance() { return $GLOBALS['emailTestLava']; }
require dirname(__DIR__) . '/scheme/database/Database.php';
require dirname(__DIR__) . '/scheme/kernel/Model.php';
require dirname(__DIR__) . '/app/models/PaymentPeriodModel.php';
function check($value, string $label): void {
    if (!$value) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . PHP_EOL;
}
class ScheduleEmailFixture extends PaymentPeriodModel {
    public array $sent = [];
    public bool $fail = false;
    public function queue(int $org, int $id): void { $this->queue_announcement($org, $id); }
    protected function deliver_announcement(array $payload): void {
        if ($this->fail) throw new RuntimeException('Simulated provider failure');
        $this->sent[] = $payload;
    }
}
class ScheduleEmailDatabase extends Database {
    public function raw($query, $args = []) {
        return parent::raw(str_replace(' FOR UPDATE', '', $query), $args);
    }
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT);
    CREATE TABLE students(id INTEGER PRIMARY KEY,user_id INTEGER,course TEXT);
    CREATE TABLE organization_payment_periods(id INTEGER PRIMARY KEY,org_user_id INTEGER,academic_period_id INTEGER,label TEXT,semester_start TEXT,semester_end TEXT,opens_at TEXT,closes_at TEXT,cap_amount TEXT,email_status TEXT,email_announcement TEXT)');
$pdo->exec("INSERT INTO users VALUES(1,'cas@example.com'),(2,'ccs@example.com'),(3,'cas@example.com'),(4,'invalid'),(5,'cbm@example.com');
    INSERT INTO students VALUES(1,1,'AB ENGLISH'),(2,2,'BSIT'),(3,3,'ABPSYCH'),(4,4,'BSIT'),(5,5,'BSTM'),(6,NULL,'BSIT');
    INSERT INTO organization_payment_periods VALUES(1,10,1,'2026 First Semester','2026-01-01','2026-12-31','2026-12-01 08:00:00','2026-12-07 17:00:00','300.00',NULL,NULL)");
$db = (new ReflectionClass(ScheduleEmailDatabase::class))->newInstanceWithoutConstructor();
foreach (['db' => $pdo, 'driver' => 'sqlite', 'db_prefix' => ''] as $key => $value) {
    (new ReflectionProperty(Database::class, $key))->setValue($db, $value);
}
$org = ['organization_name' => 'CAS', 'username' => 'cas', 'allowed_courses' => ['AB ENGLISH','ABPSYCH']];
$orgModel = new class($org) {
    public function __construct(public array $org) {}
    public function organization($id) { return $this->org; }
};
$GLOBALS['emailTestLava'] = (object) ['db' => $db, 'call' => new class($orgModel) {
    public function __construct(public $org) {}
    public function model($name) { return $this->org; }
}];
$model = new ScheduleEmailFixture();
foreach (['CAS','CCJE','CBM','CCS','BSED','BTVTED/BTLED','USG'] as $code) {
    check(filter_var(BrevoMailer::sender(['organization_name' => $code])['email'], FILTER_VALIDATE_EMAIL), $code . ' sender configured');
}
check(BrevoMailer::sender()['email'] === 'minsufinesmonitoring@gmail.com', 'general emails use default sender');
check(BrevoMailer::sender(['username' => 'btled'])['email'] === 'btvtedbtledminsufines@gmail.com', 'BTLED alias uses combined identity');
check(count($model->announcement_recipients($org)) === 1, 'CAS receives only assigned programs with duplicate emails removed');
check(count($model->announcement_recipients(['organization_name' => 'USG','allowed_courses' => ['BSIT']])) === 3, 'USG includes every course, including outside its stored program list');
check(count($model->announcement_recipients(['organization_name' => 'CCS','allowed_courses' => ['BSIT']])) === 1, 'missing and invalid email addresses are skipped');
$db->transaction();
$model->queue(10, 1);
check(count($model->sent) === 0, 'queue does not send before commit');
$db->roll_back();
$model->deliver_announcements();
check(count($model->sent) === 0, 'rolled-back schedule queues cannot send');
$db->transaction();
$model->queue(10, 1);
$db->commit();
check(!array_key_exists('email_announcement', $model->periods(10)[0]), 'recipient payload never appears in schedule API data');
$model->deliver_announcements(10);
$model->deliver_announcements(10);
check(count($model->sent) === 1, 'repeat dispatch does not duplicate accepted batch');
check($model->sent[0]['sender']['email'] === 'casminsufines@gmail.com', 'payment announcement uses creator sender');
check(count($model->sent[0]['messageVersions'][0]['to']) === 1, 'recipients cannot see other student email addresses');
check(str_starts_with($model->sent[0]['textContent'], "Dear Students,\n\n")
    && str_contains($model->sent[0]['textContent'], 'payment period for 2026 First Semester is now officially open.')
    && str_contains($model->sent[0]['textContent'], 'Payment Period Starts: December 1, 2026, 8:00 AM')
    && str_contains($model->sent[0]['textContent'], 'Payment Deadline: December 7, 2026, 5:00 PM')
    && str_ends_with($model->sent[0]['textContent'], "CAS\nFines Monitoring System"),
    'email uses requested announcement wording and saved schedule details');
$pdo->exec("UPDATE organization_payment_periods SET email_status=NULL,email_announcement=NULL");
$orgModel->org = ['organization_name' => 'UNKNOWN','allowed_courses' => ['BSIT']];
$model->queue(10, 1);
check($pdo->query('SELECT email_status FROM organization_payment_periods')->fetchColumn() === 'FAILED', 'unknown organization fails without impersonating default sender');
$orgModel->org = $org;
$model->queue(10, 1);
$model->fail = true;
$model->deliver_announcements(10);
check($pdo->query('SELECT email_status FROM organization_payment_periods')->fetchColumn() === 'FAILED', 'delivery failure is persisted');
$model->fail = false;
$model->deliver_announcements(10);
check(count($model->sent) === 1, 'uncertain failed sends are not blindly retried');
$model->queue(10, 1);
$pdo->exec("UPDATE organization_payment_periods SET email_status='SENDING'");
$model->deliver_announcements(10);
check(count($model->sent) === 1, 'in-flight batch is not claimed by another dispatcher');
echo "Payment schedule email checks passed.\n";

// Exercise the real save transaction, not only queue/dispatch methods.
$pdo->exec("ALTER TABLE users ADD role TEXT; UPDATE users SET role='ADMIN' WHERE id=1;
    DELETE FROM organization_payment_periods");
$notification = new class {
    public bool $fail = false;
    public function payment_window(...$args) {
        if ($this->fail) throw new RuntimeException('Simulated transaction failure');
    }
};
$academic = new class {
    public function current() {
        return ['id' => 1, 'status' => 'OPEN', 'label' => 'Current Semester', 'starts_on' => null, 'ends_on' => null];
    }
};
$GLOBALS['emailTestLava']->call = new class($orgModel, $notification, $academic) {
    public function __construct(public $org, public $notifications, public $academic) {}
    public function model($name) {
        return match ($name) {
            'OrgEventModel' => $this->org,
            'NotificationModel' => $this->notifications,
            'AcademicPeriodModel' => $this->academic,
            default => throw new RuntimeException('Unexpected model: ' . $name),
        };
    }
};
$day = (new DateTimeImmutable('tomorrow', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$input = ['academic_period_id' => 1, 'opens_at' => $day . ' 08:00:00', 'closes_at' => $day . ' 17:00:00'];
$notification->fail = true;
try { $model->save(10, $input); } catch (RuntimeException $error) {}
check((int) $pdo->query('SELECT COUNT(*) FROM organization_payment_periods')->fetchColumn() === 0, 'failed save rolls back schedule and email state together');
$notification->fail = false;
$model->save(10, $input);
$saved = $pdo->query('SELECT * FROM organization_payment_periods')->fetch();
check($saved['email_status'] === 'QUEUED', 'successful creation persists queued email');
$model->deliver_announcements(10);
$count = count($model->sent);
$model->save(10, $input + ['id' => $saved['id']]);
$model->deliver_announcements(10);
check(count($model->sent) === $count, 'saving same schedule again does not announce again');
try { $model->save(10, $input); } catch (DomainException $error) {}
check((int) $pdo->query('SELECT COUNT(*) FROM organization_payment_periods')->fetchColumn() === 1, 'duplicate create request rejected by existing overlap protection');
echo "Schedule save integration checks passed.\n";

for ($i = 10; $i < 65; $i++) {
    $pdo->prepare('INSERT INTO users(id,email) VALUES(?,?)')->execute([$i, 'student' . $i . '@example.com']);
    $pdo->prepare('INSERT INTO students(id,user_id,course) VALUES(?,?,?)')->execute([$i, $i, 'AB ENGLISH']);
}
$model->queue(10, (int) $saved['id']);
$before = count($model->sent);
$model->deliver_announcements(10);
check($pdo->query('SELECT email_status FROM organization_payment_periods')->fetchColumn() === 'QUEUED', 'large recipient list leaves remaining batch queued');
$model->deliver_announcements(10);
$model->deliver_announcements(10);
check(count($model->sent) === $before + 2, 'large recipient list sends exactly two bounded batches');
check(count($model->sent[$before]['messageVersions']) === 50 && count($model->sent[$before + 1]['messageVersions']) === 6, 'batch sizes cover every distinct valid recipient once');
check($model->sent[$before]['headers']['idempotencyKey'] !== $model->sent[$before + 1]['headers']['idempotencyKey'], 'each batch has a distinct provider idempotency key');

// Deletion authorization and financial-history guards.
$pdo->exec('CREATE TABLE org_events(id INTEGER PRIMARY KEY,org_user_id INTEGER);
    CREATE TABLE org_scan_records(id INTEGER PRIMARY KEY,event_id INTEGER,academic_period_id INTEGER,fine_amount TEXT);
    CREATE TABLE org_violation_records(id INTEGER PRIMARY KEY,org_user_id INTEGER,academic_period_id INTEGER,fine_amount TEXT);
    CREATE TABLE payment_claims(id INTEGER PRIMARY KEY,org_user_id INTEGER,academic_period_id INTEGER)');
function deletionDenied(callable $work, int $code, string $label): void {
    try { $work(); } catch (DomainException $error) {
        check($error->getCode() === $code, $label);
        return;
    }
    throw new RuntimeException('FAIL: ' . $label);
}
$id = (int) $saved['id'];
deletionDenied(fn () => $model->delete_window(99, $id), 404, 'another organization cannot delete window');
$pdo->exec("UPDATE organization_payment_periods SET academic_period_id=99");
deletionDenied(fn () => $model->delete_window(10, $id), 409, 'archived semester protected');
$pdo->exec("UPDATE organization_payment_periods SET academic_period_id=1,opens_at='2000-01-01 00:00:00'");
deletionDenied(fn () => $model->delete_window(10, $id), 409, 'already opened window protected');
$pdo->prepare('UPDATE organization_payment_periods SET opens_at=?,email_status=?')->execute([$input['opens_at'], 'SENDING']);
deletionDenied(fn () => $model->delete_window(10, $id), 409, 'in-flight announcement protected');
$pdo->exec("UPDATE organization_payment_periods SET email_status='SENT'; INSERT INTO org_violation_records VALUES(1,10,1,'50.00')");
deletionDenied(fn () => $model->delete_window(10, $id), 409, 'last window with fines protected');
$pdo->exec('DELETE FROM org_violation_records; INSERT INTO payment_claims VALUES(1,10,1)');
deletionDenied(fn () => $model->delete_window(10, $id), 409, 'last window with claims protected');
$pdo->exec('DELETE FROM payment_claims');
$model->delete_window(10, $id);
check($model->periods(10) === [], 'unused future window deletes successfully');
deletionDenied(fn () => $model->delete_window(10, $id), 404, 'repeated deletion handled without changing other records');
