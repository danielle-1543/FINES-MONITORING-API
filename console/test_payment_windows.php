<?php
// Isolated LavaLust/SQLite fixtures; no hosted database or OneSignal requests.
require __DIR__ . '/test_academic_periods.php';
$current = $academic->current();
$windowCall = $GLOBALS['xenditTestLava']->call;
$GLOBALS['xenditTestLava']->call = new class($windowCall, $notifications) {
    public function __construct(public $fallback, public $notifications) {}
    public function model($name) { return $name === 'NotificationModel' ? $this->notifications : $this->fallback->model($name); }
};
$pdo->exec('DELETE FROM organization_payment_periods; DELETE FROM student_notifications; DELETE FROM payment_claim_items; DELETE FROM payment_claims; CREATE TABLE student_vouchers(student_id INTEGER,org_user_id INTEGER)');
$pdo->exec("UPDATE students SET current_academic_period_id=" . $current['id'] . ",year_level='3rd Year',account_status='ACTIVE' WHERE id=90;
    INSERT INTO users VALUES(101,'STUDENT','ACTIVE',NULL),(102,'STUDENT','ACTIVE',NULL);
    UPDATE students SET user_id=102 WHERE id=70;
    UPDATE students SET user_id=101 WHERE id=90");
$day = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$input = ['academic_period_id' => $current['id'], 'opens_at' => $day . ' 00:00:00', 'closes_at' => $day . ' 12:00:00', 'cap_amount' => '50.00'];
$schedule->save(10, $input);
$firstWindow = $schedule->periods(10)[0];
check((int) $pdo->query("SELECT COUNT(*) FROM student_notifications WHERE kind='PAYMENT_WINDOW'")->fetchColumn() === 2,
    'schedule announcement creates a distinct inbox/push entry for every eligible linked student');
$schedule->save(10, $input + ['id' => $firstWindow['id']]);
check((int) $pdo->query('SELECT COUNT(*) FROM student_notifications')->fetchColumn() === 2, 'unchanged schedule save does not duplicate notifications');
$secondInput = array_replace($input, ['opens_at' => $day . ' 12:00:00', 'closes_at' => $day . ' 23:59:59', 'cap_amount' => '999.00']);
$schedule->save(10, $secondInput);
$windows = $schedule->periods(10);
check(count($windows) === 2 && $windows[1]['cap_amount'] === '50.00', 'multiple windows in the same semester inherit one original cap');
denied(fn () => $schedule->save(10, array_replace($input, ['opens_at' => $day . ' 11:00:00'])), 409, 'overlapping payment windows are rejected');
denied(fn () => $schedule->save(10, array_replace($input, ['academic_period_id' => $first])), 409, 'new windows for a previous semester are rejected');
check(!$schedule->fine_recording_open(10, (int) $current['id'], $day . ' 00:00:00')
    && !$schedule->fine_recording_open(10, (int) $current['id'], $day . ' 12:00:00')
    && $schedule->fine_recording_open(10, (int) $current['id'], $day . ' 23:59:59')
    && $schedule->fine_recording_open(20, (int) $current['id'], $day . ' 12:00:00'),
    'fine lock uses opening-inclusive closing-exclusive boundaries and organization scope');
$baseFine = ['student_id' => 90, 'org_user_id' => 10, 'academic_period_id' => $current['id'], 'legacy_assessment' => 0,
    'fine_key' => 'new-1', 'recorded_at' => $day . ' 01:00:00', 'fine_amount' => '40.00', 'paid_amount' => '30.00', 'pending_amount' => '0.00', 'discount_amount' => '0.00'];
$fines = [$baseFine, array_replace($baseFine, ['fine_key' => 'new-2', 'paid_amount' => '0.00'])];
$calculated = PaymentPeriodModel::calculate($fines, array_reverse($windows), $day . ' 13:00:00');
check($calculated[0]['payment_period_id'] === (int) $windows[1]['id'] && $calculated[0]['payment_window'] === 'OPEN'
    && array_sum(array_column($calculated, 'payable_amount')) === 50.0 && array_sum(array_column($calculated, 'outstanding')) === 20.0,
    'later open window accepts remaining fines without resetting semester cap or confirmed payments');
$atGap = PaymentPeriodModel::calculate($fines, $windows, $day . ' 23:59:59');
check($atGap[0]['payment_window'] === 'CLOSED', 'all windows closed prevents further payment');
// Exercise actual scanner writes and automatic absence finalization under the lock.
require dirname(__DIR__) . '/app/models/OrgEventModel.php';
$scanner = new class extends OrgEventModel {
    public function organization(int $orgId): array { return ['allowed_courses' => null, 'organization_name' => 'CCS']; }
};
$beforeScannerCall = $GLOBALS['xenditTestLava']->call;
$GLOBALS['xenditTestLava']->call = new class($beforeScannerCall, $scanner) {
    public function __construct(public $fallback, public $scanner) {}
    public function model($name) { return $name === 'OrgEventModel' ? $this->scanner : $this->fallback->model($name); }
};
$pdo->exec("ALTER TABLE org_events ADD scan_starts_at TEXT;
    ALTER TABLE org_violation_types ADD org_user_id INTEGER;
    ALTER TABLE org_violation_types ADD fine_amount TEXT;
    ALTER TABLE org_violation_records ADD violation_type_id INTEGER;
    UPDATE students SET qr_token='" . str_repeat('a', 64) . "',qr_token_status='ACTIVE',activation_status='ACTIVATED' WHERE id=90;
    INSERT INTO org_violation_types(id,name,org_user_id,fine_amount) VALUES(100,'Test fine',10,'25.00');
    UPDATE organization_payment_periods SET opens_at='2000-01-01 00:00:00',closes_at='2099-01-01 00:00:00';
    INSERT INTO org_events(id,org_user_id,name,event_type,violation_type_id,scan_starts_at,scan_ends_at,academic_period_id,absence_enabled,absence_fine_amount) VALUES
    (200,10,'Fine event','VIOLATION',100,'2000-01-01','2099-01-01'," . $current['id'] . ",0,'0.00'),
    (201,10,'Attendance event','ATTENDANCE',NULL,'2000-01-01','2099-01-01'," . $current['id'] . ",0,'0.00'),
    (202,10,'Missed during payments','ATTENDANCE',NULL,'2000-01-01','2020-01-01'," . $current['id'] . ",1,'25.00');
    INSERT INTO org_event_roster VALUES(202,90)");
$scanData = ['qr_token' => str_repeat('a', 64), 'event_id' => 200];
$beforeCount = $pdo->query('SELECT COUNT(*) FROM org_scan_records')->fetchColumn();
denied(fn () => $scanner->record_scan(10, $scanData), 409, 'direct violation-event confirmation is blocked during payments');
denied(fn () => $scanner->record_violation(10, ['qr_token' => str_repeat('a', 64), 'violation_type_ids' => [100]]), 409,
    'standalone fine confirmation is blocked during payments');
check($pdo->query('SELECT COUNT(*) FROM org_scan_records')->fetchColumn() === $beforeCount, 'rejected fine leaves no scan row');
$scanner->record_scan(10, array_replace($scanData, ['event_id' => 201]));
check($pdo->query('SELECT fine_amount FROM org_scan_records WHERE event_id=201')->fetchColumn() === '0.00', 'attendance without a fine remains available');
$pdo->exec("UPDATE students SET qr_token='" . str_repeat('b', 64) . "',qr_token_status='ACTIVE',activation_status='ACTIVATED' WHERE id=70");
denied(fn () => $scanner->record_scan(10, ['qr_token' => str_repeat('b', 64), 'event_id' => 201, 'violation_type_ids' => [100]]), 409,
    'adding a violation to attendance cannot bypass the payment-window lock');
check((int) $pdo->query('SELECT COUNT(*) FROM org_scan_records WHERE event_id=201 AND student_id=70')->fetchColumn() === 0,
    'mixed attendance and fine request rolls back completely');
$attendance->finalize(10);
check($pdo->query('SELECT fine_amount FROM org_scan_records WHERE event_id=202')->fetchColumn() === '0.00', 'automatic absence keeps attendance history without adding a fine during payments');
$pdo->exec("ALTER TABLE org_violation_records ADD scan_record_id INTEGER; ALTER TABLE org_violation_records ADD recorded_by INTEGER;
    UPDATE organization_payment_periods SET closes_at='2001-01-01 00:00:00'");
$scanner->record_violation(10, ['qr_token' => str_repeat('a', 64), 'violation_type_ids' => [100]]);
check((int) $pdo->query('SELECT COUNT(*) FROM org_violation_records WHERE student_id=90 AND violation_type_id=100')->fetchColumn() === 1,
    'fine recording resumes after the payment windows close');
echo "PASS: payment window policies and notifications use isolated fixtures.\n";
