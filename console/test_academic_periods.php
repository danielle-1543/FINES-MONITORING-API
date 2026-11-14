<?php
// Reuse the isolated SQLite/LavaLust Query Builder harness. Never loads .env.
require __DIR__ . '/test_xendit.php';
require dirname(__DIR__) . '/app/models/AcademicPeriodModel.php';
require dirname(__DIR__) . '/app/models/StudentModel.php';
require dirname(__DIR__) . '/app/models/FineArchiveModel.php';
require dirname(__DIR__) . '/app/models/NotificationModel.php';
require dirname(__DIR__) . '/app/models/AttendanceModel.php';
[$pdo, $unused, $gateway] = fixture();
$pdo->sqliteCreateFunction('CONCAT', fn (...$parts) => implode('', $parts));
$pdo->exec("ALTER TABLE students ADD departure_reason TEXT; ALTER TABLE students ADD year_level TEXT; ALTER TABLE students ADD section TEXT;
    ALTER TABLE students ADD qr_token TEXT DEFAULT 'unchanged-qr'; ALTER TABLE students ADD current_academic_period_id INTEGER;
    CREATE TABLE academic_periods(id INTEGER PRIMARY KEY AUTOINCREMENT,year_start INTEGER,term TEXT,label TEXT,starts_on TEXT,ends_on TEXT,status TEXT,is_current INTEGER,UNIQUE(year_start,term));
    CREATE TABLE student_change_history(id INTEGER PRIMARY KEY,student_id INTEGER,changed_by INTEGER,batch_id TEXT,reason TEXT,note TEXT,before_values TEXT,after_values TEXT,changed_at TEXT);
    CREATE TABLE academic_record_changes(id INTEGER PRIMARY KEY,changed_by INTEGER,academic_period_id INTEGER,source_kind TEXT,source_id INTEGER,change_details TEXT,changed_at TEXT);
    CREATE TABLE org_events(id INTEGER PRIMARY KEY,org_user_id INTEGER,name TEXT,violation_type_id INTEGER,scan_ends_at TEXT,academic_period_id INTEGER);
    CREATE TABLE org_violation_types(id INTEGER PRIMARY KEY,name TEXT);
    CREATE TABLE org_scan_records(id INTEGER PRIMARY KEY,student_id INTEGER,event_id INTEGER,record_type TEXT,fine_amount TEXT,recorded_at TEXT,academic_period_id INTEGER,legacy_assessment INTEGER);
    ALTER TABLE org_scan_records ADD attendance_status TEXT DEFAULT 'ABSENT';
    CREATE TABLE student_notifications(id INTEGER PRIMARY KEY,user_id INTEGER,source_key TEXT UNIQUE,title TEXT,message TEXT,kind TEXT,recorded_at TEXT,created_at TEXT,push_next_attempt_at TEXT,push_idempotency_key TEXT);
    CREATE TABLE org_violation_records(id INTEGER PRIMARY KEY,student_id INTEGER,event_id INTEGER,org_user_id INTEGER,violation_name TEXT,fine_amount TEXT,recorded_at TEXT,academic_period_id INTEGER,legacy_assessment INTEGER);
    CREATE TABLE organization_payment_periods(id INTEGER PRIMARY KEY,org_user_id INTEGER,academic_period_id INTEGER,label TEXT,semester_start TEXT,semester_end TEXT,opens_at TEXT,closes_at TEXT,cap_amount TEXT);
    INSERT INTO users VALUES(99,'ADMIN','ACTIVE',NULL);
    INSERT INTO org_events VALUES(1,10,'Old Event',NULL,'2020-01-01 10:00:00',NULL);
    INSERT INTO org_scan_records VALUES(1,2,1,'ATTENDANCE','90.00','2020-01-01 10:00:00',NULL,1,'ABSENT');
    INSERT INTO organization_payment_periods VALUES(1,10,NULL,'Legacy cap','2000-01-01','2025-12-31','2000-01-01','2099-01-01','50.00');");
$academic = new AcademicPeriodModel(); $payments = new PaymentModel(); $schedule = new PaymentPeriodModel();
$orgFixture = $GLOBALS['xenditTestLava']->call;
$GLOBALS['xenditTestLava']->call = new class($academic, $payments, $schedule, $orgFixture) {
    public function __construct(public $academic, public $payments, public $schedule, public $fallback) {}
    public function model($name) {
        if ($name === 'AcademicPeriodModel') return $this->academic;
        if ($name === 'PaymentModel') return $this->payments;
        if ($name === 'PaymentPeriodModel') return $this->schedule;
        if ($name === 'StudentModel') return new class { public function programs() { return ['BSIT' => [], 'BSCS' => []]; } };
        if ($name === 'StudentVoucherModel') return new class {
            public function attach($rows) { foreach ($rows as &$row) if ($row['fine_key'] === 'violation-50') $row['discount_amount'] = '5.00'; return $rows; }
        };
        return $this->fallback->model($name);
    }
};
function saveAcademic($academic, $input) {
    $preview = $academic->save($input + ['preview' => true], 99);
    return !empty($preview['preview']) ? $academic->save($input + ['preview' => false, 'fingerprint' => $preview['fingerprint']], 99) : $preview;
}
$first = saveAcademic($academic, ['year_start' => 2026, 'term' => 'FIRST', 'is_current' => true])['id'];
check($academic->current()['starts_on'] === null && $academic->current()['ends_on'] === null, 'semester setup requires no date fields');
denied(fn () => $academic->save(['year_start' => 2026, 'term' => 'FIRST']), 409, 'duplicate semesters rejected');
$pdo->exec("UPDATE students SET year_level='1st Year',section='F1',current_academic_period_id=$first WHERE id=2");
$studentsModel = new StudentModel();
$academic->require_student_period(2, $first);
check(true, 'scanning accepts the semester stored directly on student data');
$notifications = new NotificationModel(); $notifications->record_scan(1);
$originalNotification = $pdo->query('SELECT * FROM student_notifications')->fetch();
$preview = $academic->classify(['academic_period_id' => $first, 'kind' => 'event', 'source_id' => 1, 'preview' => true], 99);
denied(fn () => $academic->classify(['academic_period_id' => $first, 'kind' => 'event', 'source_id' => 1, 'preview' => false, 'fingerprint' => 'stale'], 99), 409, 'legacy classification requires matching preview');
$academic->classify(['academic_period_id' => $first, 'kind' => 'event', 'source_id' => 1, 'preview' => false, 'fingerprint' => $preview['fingerprint']], 99);
$notifications->record_scan(1);
check($pdo->query('SELECT * FROM student_notifications')->fetch() === $originalNotification && (int) $pdo->query('SELECT COUNT(*) FROM student_notifications')->fetchColumn() === 1, 'legacy classification preserves notification ID and push idempotency without duplicates');
$old = $payments->fines(2)[0];
check($old['payable_amount'] === '50.00' && (int) $old['legacy_assessment'] === 1, 'classifying a legacy fine preserves its original cap');
$second = saveAcademic($academic, ['year_start' => 2026, 'term' => 'SECOND', 'is_current' => true])['id'];
$update = ['student_ids' => [2], 'reason' => 'SEMESTER_CHANGE', 'changes' => ['current_academic_period_id' => (string) $second, 'year_level' => '2nd Year', 'section' => 'F2'], 'preview' => true];
$review = $studentsModel->update_students($update, 99);
$studentsModel->update_students(array_replace($update, ['preview' => false, 'fingerprint' => $review['fingerprint']]), 99);
check((int) $pdo->query('SELECT current_academic_period_id FROM students WHERE id=2')->fetchColumn() === $second, 'existing edit preview and confirmation update student semester');
denied(fn () => $academic->require_student_period(2, $first), 409, 'scanning compares the current student semester without separate enrollment');
$student = $pdo->query('SELECT * FROM students WHERE id=2')->fetch();
check($student['qr_token'] === 'unchanged-qr' && $student['user_id'] == 1 && $student['year_level'] === '2nd Year', 'rollover updates profile while preserving account and QR');
check((int) $pdo->query('SELECT academic_period_id FROM org_scan_records WHERE id=1')->fetchColumn() === $first, 'current semester change never relabels previous fine');
$pdo->exec("INSERT INTO org_events VALUES(2,10,'New Event',NULL,'2026-12-01 10:00:00',$second);
    INSERT INTO org_scan_records VALUES(2,2,2,'ATTENDANCE','80.00','2026-11-14 10:00:00',$second,0,'ABSENT');
    INSERT INTO organization_payment_periods VALUES(2,10,$second,'Second cap','1000-01-01','9999-12-31','2000-01-01','2099-01-01','60.00');");
$rows = array_column($payments->fines(2), null, 'fine_key');
check($rows['scan-1']['payable_amount'] === '50.00' && $rows['scan-2']['payable_amount'] === '60.00', 'new semester cap is separate from legacy assessed fines');
$notifications->record_scan(2); $notifications->record_scan(2);
check((int) $pdo->query("SELECT COUNT(*) FROM student_notifications WHERE source_key='scan:2'")->fetchColumn() === 1, 'new semester fines still generate one notification');
$claim = $payments->submit(1, ['fine_key' => 'ALL', 'method' => 'CASH', 'organization_id' => 10, 'academic_period_id' => $second, 'amount' => '25.00']);
check($pdo->query("SELECT fine_key FROM payment_claim_items WHERE claim_id=$claim")->fetchColumn() === 'scan-2', 'partial payment allocates only to selected semester');
$pdo->exec("UPDATE payment_claims SET status='CONFIRMED' WHERE id=$claim");
$rows = array_column($payments->fines(2), null, 'fine_key');
check($rows['scan-2']['outstanding'] === '35.00' && $rows['scan-1']['outstanding'] === '50.00', 'confirmed partial payment leaves exact semester balance');
$next = $payments->submit(1, ['fine_key' => 'ALL', 'method' => 'CASH', 'organization_id' => 10, 'academic_period_id' => $second, 'amount' => '35.00']);
$pdo->exec("UPDATE payment_claims SET status='CONFIRMED' WHERE id=$next");
check(array_column($payments->fines(2), null, 'fine_key')['scan-2']['outstanding'] === '0.00', 'full settlement makes capped semester balance zero');
denied(fn () => $payments->submit(1, ['fine_key' => 'ALL', 'organization_id' => 10, 'amount' => '0'], null, 'zero-check'), 422, 'zero Xendit amount cannot silently become full balance');
$archive = new FineArchiveModel();
$report = $archive->report(['role' => 'ADMIN', 'sub' => 99], ['academic_period_id' => (string) $second]);
check(count($report['fines']) === 1 && $report['fines'][0]['settlement_status'] === 'PAID' && count($report['payments']) === 2, 'archives retain paid fine with both partial-payment allocations');
check(!$archive->report(['role' => 'ORGANIZATION', 'sub' => 20], [])['fines'], 'organization cannot read another organization archives');
$pdo->exec("INSERT INTO org_scan_records VALUES(90,2,2,'ATTENDANCE','0.00','2026-11-15 08:00:00',$second,0,'ABSENT');
    INSERT INTO org_scan_records VALUES(91,999,2,'ATTENDANCE','999.00','2026-11-15 09:00:00',$second,0,'ABSENT');
    INSERT INTO org_violation_records VALUES(50,2,NULL,20,'Uniform violation','40.00','2026-11-15 10:00:00',$second,0);
    INSERT INTO organization_payment_periods VALUES(20,20,$second,'USG cap','1000-01-01','9999-12-31','2000-01-01','2099-01-01','30.00');
    INSERT INTO payment_claims(student_id,org_user_id,fine_key,amount,method,status) VALUES
        (2,20,'violation-50','10.00','CASH','CONFIRMED'),(2,20,'violation-50','8.00','CASH','PENDING'),(2,20,'violation-50','999.00','CASH','CANCELLED');");
$personal = $archive->report(['role' => 'STUDENT', 'sub' => 1], ['student_id' => '999', 'academic_period_id' => (string) $first, 'export' => '1', 'status' => 'UNPAID']);
check($personal['current_period']['id'] === $second && count($personal['records']) === 3, 'student archive enforces authenticated student and current semester despite supplied filters');
check(array_column($personal['records'], 'id') === ['violation-50', 'scan-90', 'scan-2'], 'one chronological list includes zero-fine absences and keeps fully paid fines visible');
check(count($personal['organizations']) === 2 && !isset($personal['payments']), 'student archive has dynamic organization breakdown without allocation tables');
check($personal['summary'] === ['original' => '120.00', 'cap_adjustment' => '30.00', 'discount' => '5.00', 'payable' => '85.00', 'paid' => '70.00', 'outstanding' => '15.00'], 'personal summary applies separate caps and vouchers; pending/cancelled payments never reduce balance');
$organizationExport = $payments->organization_fines(10, [], true);
$exportedFines = array_column($organizationExport['data'][0]['fines'], null, 'fine_key');
check($exportedFines['scan-2']['cap_adjustment'] === '20.00' && $exportedFines['scan-2']['academic_period_label'] === $academic->period($second)['label'], 'organization exports preserve original semester and apply the same cap as the student ledger');
check(str_contains($organizationExport['data'][0]['academic_period_label'], $academic->period($first)['label']) && str_contains($organizationExport['data'][0]['academic_period_label'], $academic->period($second)['label']), 'cross-semester aggregate exports list every contributing semester instead of labelling them current');
$statistics = $archive->dashboard_statistics(1);
check($statistics['current_period']['id'] === $second && $statistics['summary'] === ['total_violations' => 1, 'total_fines' => '120.00', 'paid_amount' => '70.00', 'unpaid_fines' => '15.00'], 'dashboard statistics count current violations separately from absences and reuse verified adjusted balances');
$pdo->exec("INSERT INTO payment_claims(student_id,org_user_id,fine_key,amount,method,status) VALUES(2,10,'scan-1','5.00','CASH','CONFIRMED')");
check($archive->dashboard_statistics(1) === $statistics, 'verified payments for another semester cannot inflate current dashboard statistics');
$pdo->exec("ALTER TABLE org_events ADD event_type TEXT; ALTER TABLE org_events ADD absence_enabled INTEGER DEFAULT 0;
    ALTER TABLE org_events ADD attendance_finalized_at TEXT; ALTER TABLE org_events ADD absence_fine_amount TEXT;
    ALTER TABLE org_scan_records ADD recorded_by INTEGER;
    CREATE TABLE org_event_roster(event_id INTEGER,student_id INTEGER,PRIMARY KEY(event_id,student_id));
    INSERT INTO org_events(id,org_user_id,name,scan_ends_at,academic_period_id,event_type,absence_enabled,absence_fine_amount)
        VALUES(3,10,'Ended semester event','2020-01-02 10:00:00',$first,'ATTENDANCE',1,'15.00');
    INSERT INTO org_event_roster VALUES(3,2);");
$previousCall = $GLOBALS['xenditTestLava']->call;
$GLOBALS['xenditTestLava']->call = new class($previousCall) {
    public function __construct(public $previous) {}
    public function model($name) { return $name === 'NotificationModel' ? new NotificationModel() : $this->previous->model($name); }
};
$attendance = new AttendanceModel();
check($attendance->finalize(10) === 1 && $attendance->finalize(10) === 0, 'absence finalization remains idempotent with semester columns');
$absence = $pdo->query('SELECT * FROM org_scan_records WHERE event_id=3')->fetch();
check((int) $absence['academic_period_id'] === $first && (int) $absence['legacy_assessment'] === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM student_notifications WHERE source_key='scan:" . $absence['id'] . "'")->fetchColumn() === 1,
    'automatic absence inherits event semester and still queues one notification');
$academic->save(['id' => $first, 'year_start' => 2026, 'term' => 'FIRST', 'status' => 'CLOSED']);
denied(fn () => $academic->recording_period($first, '2026-11-14'), 409, 'closed semester rejects new recording');
check((int) $academic->current()['id'] === $second, 'closing older semester leaves current selection intact');
$pdo->exec("INSERT INTO students(id,user_id,course,account_status,activation_status,full_name,student_number,year_level,section,current_academic_period_id)
    VALUES(3,NULL,'BSIT','ACTIVE','PENDING','Second Student','DEMO-2','1st Year','F1',$first)");
$batch = ['student_ids' => [2,3], 'reason' => 'SEMESTER_CHANGE', 'changes' => ['current_academic_period_id' => (string) $second, 'year_level' => '3rd Year', 'section' => 'F3'], 'preview' => true];
$review = $studentsModel->update_students($batch, 99);
denied(fn () => $studentsModel->update_students(array_replace($batch, ['preview' => false, 'fingerprint' => 'stale']), 99), 409, 'multiple edits reject an unreviewed semester change');
$studentsModel->update_students(array_replace($batch, ['preview' => false, 'fingerprint' => $review['fingerprint']]), 99);
check((int) $pdo->query("SELECT COUNT(*) FROM students WHERE current_academic_period_id=$second AND year_level='3rd Year' AND section='F3'")->fetchColumn() === 2, 'multiple edits change year, section and semester for every selected student');
check($pdo->query('SELECT qr_token FROM students WHERE id=2')->fetchColumn() === 'unchanged-qr'
    && (int) $pdo->query('SELECT academic_period_id FROM org_scan_records WHERE id=1')->fetchColumn() === $first,
    'multiple edits preserve QR identity and previous fine semesters');
$pdo->exec("INSERT INTO org_events(id,org_user_id,name,scan_ends_at,academic_period_id) VALUES(4,10,'Next event','2099-01-01',$second)");
$attendance->snapshot(4, 10);
check((int) $pdo->query('SELECT COUNT(*) FROM org_event_roster WHERE event_id=4 AND student_id=2')->fetchColumn() === 1, 'attendance roster uses student semester without an enrollment table');
foreach (['qr_token_status', 'qr_token_generated_at', 'activation_code_hash', 'activation_code', 'activation_code_expires_at'] as $field) $pdo->exec('ALTER TABLE students ADD ' . $field . ' TEXT');
$studentsModel->register_for_period(['student_number' => 'DEMO-NEW', 'full_name' => 'New Student', 'course' => 'BSIT', 'year_level' => '1st Year', 'section' => 'F1', 'account_status' => 'ACTIVE', 'activation_status' => 'PENDING', 'qr_token' => 'new-qr'], $second);
check((int) $pdo->query("SELECT current_academic_period_id FROM students WHERE student_number='DEMO-NEW'")->fetchColumn() === $second, 'registration saves semester directly on student data without a second workflow');
$pdo->exec("UPDATE students SET current_academic_period_id=$first WHERE id=2");
check(count($archive->report(['role' => 'STUDENT', 'sub' => 1], [])['records']) === 3, 'profile semester changes cannot reassign archived sources or override Academic Settings');
$pdo->exec("INSERT INTO org_violation_records VALUES(51,2,NULL,20,'Warning','0.00','2026-11-16 10:00:00',$second,0)");
check($archive->dashboard_statistics(1)['summary']['total_violations'] === 2, 'current violation count includes zero-amount violations');
$pdo->exec('UPDATE academic_periods SET is_current=0');
$empty = $archive->report(['role' => 'STUDENT', 'sub' => 1], ['academic_period_id' => 'all']);
check($empty['current_period'] === null && !$empty['records'] && !$empty['organizations'], 'missing active semester returns empty state with no historical fallback');
$pdo->exec("UPDATE academic_periods SET is_current=1,status='CLOSED' WHERE id=$second");
check($archive->report(['role' => 'STUDENT', 'sub' => 1], [])['current_period'] === null, 'closed current semester cannot expose closed-period student archives');
check($archive->dashboard_statistics(1) === ['current_period' => null, 'summary' => ['total_violations' => 0, 'total_fines' => '0.00', 'paid_amount' => '0.00', 'unpaid_fines' => '0.00']], 'dashboard returns no current period and zero statistics when the semester is closed');
$pdo->exec("UPDATE academic_periods SET status='OPEN',is_current=1 WHERE id=$second");
$nextSemester = ['year_start' => 2027, 'term' => 'FIRST', 'is_current' => true];
$pdo->exec("INSERT INTO students(id,user_id,course,account_status,activation_status,full_name,student_number,year_level,section,current_academic_period_id) VALUES
    (70,NULL,'BSIT','ACTIVE','PENDING','Second Year Student','DEMO-Y2','2nd Year','F2',$second),
    (71,NULL,'BSIT','ACTIVE','PENDING','Fourth Year Student','DEMO-Y4','4th Year','F4',$second)");
$beforeStudents = $pdo->query('SELECT id,student_number,full_name,course,year_level,section,current_academic_period_id FROM students ORDER BY id')->fetchAll();
$preserved = [];
foreach (['org_events','org_scan_records','org_violation_records','payment_claims','payment_claim_items','student_notifications'] as $table) $preserved[$table] = $pdo->query('SELECT * FROM ' . $table)->fetchAll();
denied(fn () => $academic->save($nextSemester, 99), 409, 'semester rollover requires explicit preview and confirmation');
$rolloverPreview = $academic->save($nextSemester + ['preview' => true], 99);
check($rolloverPreview['advancing_academic_year'] && $rolloverPreview['year_levels_promoted'] === 4 && $rolloverPreview['fourth_year_unchanged'] === 1, 'academic year confirmation previews promotions and fourth-year retention');
check((int) $academic->current()['id'] === $second && count($academic->periods()) === 2, 'cancelled rollover preview leaves current semester, students and new period unchanged');
$pdo->exec("UPDATE students SET section='F9' WHERE id=3");
denied(fn () => $academic->save($nextSemester + ['preview' => false, 'fingerprint' => $rolloverPreview['fingerprint']], 99), 409, 'rollover rejects a stale preview after student data changes');
$pdo->exec("UPDATE students SET section='F3' WHERE id=3");
$rolloverPreview = $academic->save($nextSemester + ['preview' => true], 99);
$rollover = $academic->save($nextSemester + ['preview' => false, 'fingerprint' => $rolloverPreview['fingerprint']], 99);
check($rollover['students_updated'] === count($beforeStudents) && (int) $pdo->query('SELECT COUNT(*) FROM students WHERE current_academic_period_id=' . $rollover['id'])->fetchColumn() === count($beforeStudents), 'confirmed rollover updates every student to the new semester');
check($academic->period($second)['status'] === 'CLOSED', 'rollover closes the previous semester');
$yearLevels = $pdo->query('SELECT id,year_level FROM students ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
check($yearLevels[2] === '4th Year' && $yearLevels[70] === '3rd Year' && $yearLevels[71] === '4th Year'
    && $pdo->query("SELECT year_level FROM students WHERE student_number='DEMO-NEW'")->fetchColumn() === '2nd Year',
    'new academic year promotes first/second/third year by one level and keeps fourth year unchanged');
denied(fn () => $studentsModel->register_for_period(['student_number' => 'STALE'], $second), 409, 'registration rejects a stale period after Academic Settings rollover');
$saved = $academic->semester_history()[0]['snapshot'];
check($saved['students'] === $beforeStudents && (int) $saved['from']['id'] === $second, 'previous semester records retain exact student data from before rollover');
foreach ($preserved as $table => $rows) check($rows === $pdo->query('SELECT * FROM ' . $table)->fetchAll(), 'rollover preserves original rows in ' . $table);
$failedSwitch = ['year_start' => 2027, 'term' => 'SECOND', 'is_current' => true];
$failurePreview = $academic->save($failedSwitch + ['preview' => true], 99);
$pdo->exec("CREATE TRIGGER prevent_rollover_history BEFORE INSERT ON student_change_history WHEN NEW.reason='SEMESTER_ROLLOVER' BEGIN SELECT RAISE(ABORT,'simulated audit failure'); END");
try {
    $academic->save($failedSwitch + ['preview' => false, 'fingerprint' => $failurePreview['fingerprint']], 99);
    throw new RuntimeException('Failed rollover unexpectedly committed.');
} catch (PDOException $expected) {
    check((int) $academic->current()['id'] === $rollover['id'] && count($academic->periods()) === 3
        && (int) $pdo->query('SELECT COUNT(*) FROM students WHERE current_academic_period_id=' . $rollover['id'])->fetchColumn() === count($beforeStudents),
        'audit failure rolls back period creation, closing and all student changes together');
}
$pdo->exec('DROP TRIGGER prevent_rollover_history');
$semesterOnly = saveAcademic($academic, $failedSwitch);
check($semesterOnly['year_levels_promoted'] === 0 && $pdo->query('SELECT id,year_level FROM students ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR) === $yearLevels, 'changing semester within one academic year never promotes students');
saveAcademic($academic, array_replace($academic->period($second), ['is_current' => true, 'status' => 'OPEN']));
check($pdo->query('SELECT id,year_level FROM students ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR) === $yearLevels, 'switching to an older academic year never demotes students');
$repeatYear = saveAcademic($academic, array_replace($academic->period($rollover['id']), ['is_current' => true, 'status' => 'OPEN']));
check($repeatYear['year_levels_promoted'] === 0 && $pdo->query('SELECT id,year_level FROM students ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR) === $yearLevels, 'returning to an already processed academic year cannot promote students twice');
$promotionFailure = ['year_start' => 2028, 'term' => 'FIRST', 'is_current' => true];
$promotionPreview = $academic->save($promotionFailure + ['preview' => true], 99);
$pdo->exec("CREATE TRIGGER prevent_year_level_change BEFORE UPDATE ON students WHEN NEW.year_level<>OLD.year_level BEGIN SELECT RAISE(ABORT,'simulated promotion failure'); END");
try {
    $academic->save($promotionFailure + ['preview' => false, 'fingerprint' => $promotionPreview['fingerprint']], 99);
    throw new RuntimeException('Failed year-level promotion unexpectedly committed.');
} catch (PDOException $expected) {
    check((int) $academic->current()['id'] === $rollover['id'] && $pdo->query('SELECT id,year_level FROM students ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR) === $yearLevels
        && (int) $pdo->query('SELECT COUNT(*) FROM students WHERE current_academic_period_id=' . $rollover['id'])->fetchColumn() === count($beforeStudents),
        'promotion failure rolls back all semester and year-level changes atomically');
}
$pdo->exec('DROP TRIGGER prevent_year_level_change');
$graduation = ['student_ids' => [2,71], 'reason' => 'GRADUATED', 'changes' => [], 'preview' => true];
denied(fn () => $studentsModel->update_students(array_replace($graduation, ['student_ids' => [2,70]]), 99), 409, 'graduation rejects a selection containing a non-fourth-year student');
$graduateBefore = $pdo->query('SELECT * FROM students WHERE id=2')->fetch();
$graduatePayments = $pdo->query('SELECT * FROM payment_claims')->fetchAll();
$graduateReview = $studentsModel->update_students($graduation, 99);
check($pdo->query('SELECT year_level FROM students WHERE id=2')->fetchColumn() === '4th Year', 'graduation preview does not archive students before confirmation');
$studentsModel->update_students(array_replace($graduation, ['preview' => false, 'fingerprint' => $graduateReview['fingerprint']]), 99);
$graduateAfter = $pdo->query('SELECT * FROM students WHERE id=2')->fetch();
check($graduateAfter['year_level'] === 'Graduated' && $graduateAfter['qr_token'] === $graduateBefore['qr_token']
    && $graduateAfter['account_status'] === $graduateBefore['account_status'] && $pdo->query('SELECT * FROM payment_claims')->fetchAll() === $graduatePayments,
    'confirmed graduation preserves account, QR and payment records');
$currentIds = $studentsModel->matching_student_ids([], false);
$graduateIds = $studentsModel->matching_student_ids(['year_level' => 'Graduated']);
check(!in_array(2, $currentIds, true) && !in_array(71, $currentIds, true) && in_array(2, $graduateIds, true) && in_array(71, $graduateIds, true), 'default student list excludes graduates and Graduated filter retrieves archived records');
denied(fn () => $academic->require_student_period(2, $rollover['id']), 409, 'graduated student cannot receive new semester scan records');
$pdo->exec("INSERT INTO org_events(id,org_user_id,name,scan_ends_at,academic_period_id) VALUES(8,10,'New roster','2099-01-01'," . $rollover['id'] . ")");
$attendance->snapshot(8,10);
check((int) $pdo->query('SELECT COUNT(*) FROM org_event_roster WHERE event_id=8 AND student_id=2')->fetchColumn() === 0, 'new attendance rosters exclude graduates');
// Graduation must not erase a missed absence from an event that ended before graduation.
$pdo->exec("UPDATE student_change_history SET changed_at='2020-01-02 00:00:00' WHERE reason='GRADUATED';
    INSERT INTO org_events(id,org_user_id,name,scan_ends_at,academic_period_id,event_type,absence_enabled,absence_fine_amount) VALUES
        (10,10,'Before graduation','2020-01-01 12:00:00',$first,'ATTENDANCE',1,'15.00'),
        (11,10,'After graduation','2020-01-03 12:00:00',$first,'ATTENDANCE',1,'15.00');
    INSERT INTO org_event_roster VALUES(10,2),(11,2)");
check($attendance->finalize(10) === 1 && (int) $pdo->query('SELECT COUNT(*) FROM org_scan_records WHERE event_id=10 AND student_id=2')->fetchColumn() === 1
    && (int) $pdo->query('SELECT COUNT(*) FROM org_scan_records WHERE event_id=11 AND student_id=2')->fetchColumn() === 0,
    'absence finalization keeps pre-graduation history but cannot fine graduates for later roster events');
$graduatePeriod = $graduateAfter['current_academic_period_id'];
$afterGraduationPreview = $academic->save($promotionFailure + ['preview' => true], 99);
check($afterGraduationPreview['graduates_excluded'] === 2 && $afterGraduationPreview['students'] === count($beforeStudents)-2, 'rollover confirmation counts only non-graduates and reports excluded graduates');
$afterGraduation = $academic->save($promotionFailure + ['preview' => false, 'fingerprint' => $afterGraduationPreview['fingerprint']], 99);
check($afterGraduation['students_updated'] === count($beforeStudents)-2 && $pdo->query('SELECT current_academic_period_id FROM students WHERE id=2')->fetchColumn() == $graduatePeriod
    && $pdo->query('SELECT year_level FROM students WHERE id=71')->fetchColumn() === 'Graduated', 'future year rollover leaves graduates archived in their last academic period');
echo "PASS: academic fixtures use real LavaLust Query Builder with no hosted database access.\n";
