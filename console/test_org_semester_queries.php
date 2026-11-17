<?php
// Real LavaLust query builder, isolated SQLite data; no production connection.
define('PREVENT_DIRECT_ACCESS', true);
function config_item($key) { return ['timestamps' => false, 'soft_delete' => false][$key] ?? null; }
function load_class($name, $directory = '') {
    return new class {
        public function show_database_error($message, $sql = '', $bindings = [], $error = null) {
            throw $error ?: new RuntimeException($message);
        }
    };
}
function lava_instance() { return $GLOBALS['orgQueryFixture']; }
require dirname(__DIR__) . '/scheme/database/Database.php';
require dirname(__DIR__) . '/scheme/kernel/Model.php';
require dirname(__DIR__) . '/app/models/AcademicPeriodModel.php';
require dirname(__DIR__) . '/app/models/OrgEventModel.php';
require dirname(__DIR__) . '/app/models/OrganizationDashboardModel.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE academic_periods(id INTEGER PRIMARY KEY,is_current INTEGER);
    INSERT INTO academic_periods VALUES(1,0),(2,1);
    CREATE TABLE org_events(id INTEGER PRIMARY KEY,org_user_id INTEGER,name TEXT,event_type TEXT,violation_type_id INTEGER,academic_period_id INTEGER,scan_starts_at TEXT,scan_ends_at TEXT,session_open INTEGER,created_at TEXT,absence_enabled INTEGER,attendance_finalized_at TEXT,absence_fine_amount TEXT);
    CREATE TABLE org_violation_types(id INTEGER PRIMARY KEY,org_user_id INTEGER,name TEXT,fine_amount TEXT);
    CREATE TABLE students(id INTEGER PRIMARY KEY,course TEXT);
    CREATE TABLE org_scan_records(id INTEGER PRIMARY KEY,event_id INTEGER,student_id INTEGER,record_type TEXT,academic_period_id INTEGER);
    INSERT INTO students VALUES(1,'BSIT'),(2,'BSTM');
    INSERT INTO org_events(id,org_user_id,academic_period_id) VALUES(1,10,1),(2,10,2),(3,20,2);
    INSERT INTO org_scan_records VALUES(1,1,1,'ATTENDANCE',1),(2,2,1,'ATTENDANCE',2),(3,3,1,'ATTENDANCE',2),(4,2,2,'ATTENDANCE',2)");
$db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
foreach (['db' => $pdo, 'driver' => 'sqlite', 'db_prefix' => ''] as $key => $value) {
    (new ReflectionProperty(Database::class, $key))->setValue($db, $value);
}
$GLOBALS['orgQueryFixture'] = (object) ['db' => $db, 'call' => new class {
    public function model($name) { return new $name(); }
}];
function check($ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . PHP_EOL;
}
$events = new OrgEventModel();
$dashboard = new OrganizationDashboardModel();
$attendance = new ReflectionMethod(OrganizationDashboardModel::class, 'attendance_query');
check(array_column($events->events(10), 'id') === [2], 'event query retains organization and current-semester filters');
$rows = $attendance->invoke($dashboard, 10, ['BSIT'])->select('r.id')->get_all();
check(array_column($rows, 'id') === [2], 'dashboard attendance retains semester, organization and course filters');
$pdo->exec('DELETE FROM org_events WHERE org_user_id=20');
check(count($events->events(10)) === 1, 'removing another organization event does not affect CCS query');
$pdo->exec('UPDATE academic_periods SET is_current=0');
check($events->events(10) === [], 'missing current semester returns empty events without query failure');
check($attendance->invoke($dashboard, 10, ['BSIT'])->select('r.id')->get_all() === [], 'missing current semester returns empty attendance without query failure');
