<?php
// Isolated SQLite fixtures; no hosted database, credentials or provider calls.
require __DIR__ . '/test_xendit.php';
require dirname(__DIR__) . '/app/models/AdminOrganizationModel.php';
require dirname(__DIR__) . '/app/models/ActivityLogModel.php';
[$pdo] = fixture();
$pdo->exec("ALTER TABLE users ADD username TEXT; ALTER TABLE users ADD email TEXT;
    ALTER TABLE users ADD organization_scope TEXT DEFAULT 'ALL'; ALTER TABLE users ADD organization_course TEXT;
    ALTER TABLE users ADD auth_version INTEGER DEFAULT 0; ALTER TABLE users ADD organization_deleted_at DATETIME;
    UPDATE users SET username='ccs',email='ccs@example.test' WHERE id=10;
    INSERT INTO users(id,role,status,username,organization_name) VALUES(99,'ADMIN','ACTIVE','admin',NULL);
    CREATE TABLE organization_programs(org_user_id INTEGER,program TEXT);
    INSERT INTO organization_programs VALUES(10,'BSIT');
    CREATE TABLE org_events(id INTEGER PRIMARY KEY,org_user_id INTEGER,name TEXT);
    INSERT INTO org_events VALUES(1,10,'Historic event');
    CREATE TABLE org_violation_types(id INTEGER PRIMARY KEY,org_user_id INTEGER);
    CREATE TABLE student_notifications(id INTEGER PRIMARY KEY,message TEXT);
    INSERT INTO student_notifications VALUES(1,'CCS fine recorded');
    CREATE TABLE refresh_tokens(id INTEGER PRIMARY KEY,user_id INTEGER);
    INSERT INTO refresh_tokens VALUES(1,10),(2,20);
    CREATE TABLE email_otps(id INTEGER PRIMARY KEY,user_id INTEGER);
    INSERT INTO email_otps VALUES(1,10),(2,20);
    CREATE TABLE activity_logs(id INTEGER PRIMARY KEY,actor_id INTEGER,actor_name TEXT,actor_role TEXT,action TEXT,created_at TEXT);
    INSERT INTO payment_claims(student_id,org_user_id,fine_key,amount,method,status,receipt_number) VALUES(2,10,'scan-1','30.00','CASH','CONFIRMED','TEST-RECEIPT');
    INSERT INTO payment_claim_items VALUES(1,'scan-1','30.00');");
$oldCall = $GLOBALS['xenditTestLava']->call;
$GLOBALS['xenditTestLava']->call = new class($oldCall) {
    public function __construct(public $fallback) {}
    public function model($name) {
        if ($name === 'ActivityLogModel') return new ActivityLogModel();
        if ($name === 'ProgramCatalogModel') return new class { public function catalog() { return ['organization_colleges' => ['CCS'], 'organization_course_groups' => ['CCS' => ['BSIT']], 'student_programs' => ['BSIT' => 'Information Technology']]; } };
        return $this->fallback->model($name);
    }
};
$model = new AdminOrganizationModel();
$input = ['id' => 10, 'organization_name' => 'CCS', 'confirmed' => true];
denied(fn () => $model->delete_account(array_replace($input, ['confirmed' => false]), 99), 422, 'organization deletion requires confirmation');
denied(fn () => $model->delete_account($input, 1), 403, 'student cannot delete an organization account');
denied(fn () => $model->delete_account(array_replace($input, ['id' => 99]), 99), 404, 'organization deletion cannot target an administrator');
denied(fn () => $model->delete_account(array_replace($input, ['organization_name' => 'Old name']), 99), 409, 'stale organization confirmation cannot delete renamed account');
$pdo->exec("INSERT INTO payment_claims(student_id,org_user_id,fine_key,amount,method,status) VALUES(2,20,'scan-2','10.00','CASH','PENDING')");
denied(fn () => $model->delete_account(['id' => 20, 'organization_name' => 'USG', 'confirmed' => true], 99), 409, 'pending payment claims must be resolved before account deletion');
check($pdo->query('SELECT status FROM users WHERE id=20')->fetchColumn() === 'ACTIVE', 'blocked deletion preserves organization access');
$pdo->exec("CREATE TRIGGER fail_deletion_audit BEFORE INSERT ON activity_logs BEGIN SELECT RAISE(ABORT,'simulated log failure'); END");
try { $model->delete_account($input, 99); throw new RuntimeException('Deletion audit failure unexpectedly committed.'); }
catch (PDOException $expected) {
    check($pdo->query('SELECT status FROM users WHERE id=10')->fetchColumn() === 'ACTIVE'
        && (int) $pdo->query('SELECT COUNT(*) FROM refresh_tokens WHERE user_id=10')->fetchColumn() === 1, 'audit failure rolls back deletion and session removal together');
}
$pdo->exec('DROP TRIGGER fail_deletion_audit');
$preserved = [];
foreach (['payment_claims','payment_claim_items','org_events','organization_programs','student_notifications'] as $table) $preserved[$table] = $pdo->query('SELECT * FROM ' . $table)->fetchAll();
$model->delete_account($input, 99);
$deleted = $pdo->query('SELECT * FROM users WHERE id=10')->fetch();
check($deleted['status'] === 'INACTIVE' && $deleted['organization_deleted_at'] !== null && (int) $deleted['auth_version'] === 1,
    'deletion disables the account and invalidates previously issued access tokens');
check((int) $pdo->query('SELECT COUNT(*) FROM refresh_tokens WHERE user_id=10')->fetchColumn() === 0
    && (int) $pdo->query('SELECT COUNT(*) FROM email_otps WHERE user_id=10')->fetchColumn() === 0, 'deletion removes organization refresh sessions and outstanding OTP challenges');
check((int) $pdo->query('SELECT COUNT(*) FROM refresh_tokens WHERE user_id=20')->fetchColumn() === 1, 'other organization sessions are preserved');
foreach ($preserved as $table => $rows) check($pdo->query('SELECT * FROM ' . $table)->fetchAll() === $rows, 'account deletion preserves ' . $table);
$listing = $model->listing([]);
check(!in_array(10, array_map('intval', array_column($listing['data'], 'id')), true) && $listing['summary']['total'] === 3, 'deleted organization disappears from listings and account totals');
check(!$model->listing(['search' => 'CCS'], true)['data'], 'organization exports omit deleted accounts');
require dirname(__DIR__) . '/app/models/AdminDashboardModel.php';
check((new AdminDashboardModel())->overview([])['organization_count'] === 3, 'admin dashboard organization count excludes deleted accounts without changing historical analytics');
denied(fn () => $model->save(['id' => 10, 'organization_name' => 'CCS', 'username' => 'ccs', 'email' => 'ccs@example.test', 'organization_scope' => 'ALL', 'status' => 'ACTIVE'], true), 404, 'editing cannot reactivate a deleted organization');
$model->delete_account($input, 99);
check((int) $pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn() === 1 && $deleted['email'] === 'ccs@example.test', 'repeated deletion is idempotent and historical identity remains linked');
require dirname(__DIR__) . '/app/models/AttendanceModel.php';
$pdo->exec("ALTER TABLE org_events ADD event_type TEXT; ALTER TABLE org_events ADD absence_enabled INTEGER;
    ALTER TABLE org_events ADD attendance_finalized_at TEXT; ALTER TABLE org_events ADD scan_ends_at TEXT;
    UPDATE org_events SET event_type='ATTENDANCE',absence_enabled=1,scan_ends_at='2000-01-01 00:00:00'");
check((new AttendanceModel())->finalize(10) === 0, 'deleted organization cannot generate new background absence fines');
require dirname(__DIR__) . '/app/libraries/FinesMigrationRunner.php';
check(isset(FinesMigrationRunner::definitions()['organization_account_deletion']), 'organization deletion migration is registered with the existing runner');
class OrganizationMigrationFixture extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if (str_contains($query, 'information_schema.columns')) $query = "SELECT COUNT(*) FROM pragma_table_info('users') WHERE ?='users' AND name=?";
        return parent::prepare($query, $options);
    }
}
$migrationDb = new OrganizationMigrationFixture('sqlite::memory:');
$migrationDb->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');
$migration = new Organization_account_deletion();
$migration->up($migrationDb); $migration->up($migrationDb);
check((int) $migrationDb->query("SELECT COUNT(*) FROM pragma_table_info('users') WHERE name='organization_deleted_at'")->fetchColumn() === 1, 'organization deletion schema migration is additive and idempotent');
// Simulate an older deployment schema, only inside this in-memory fixture.
$pdo->exec('ALTER TABLE users DROP COLUMN organization_deleted_at');
$legacyListing = $model->listing([]);
check($legacyListing['deletion_available'] === false && $legacyListing['summary']['total'] === 4,
    'organization listing remains available when the deletion migration is not installed');
check(count($model->listing([], true)['data']) === 4, 'organization export also works before the new column exists');
check((new AdminDashboardModel())->overview([])['organization_count'] === 4,
    'admin dashboard works against the previous database schema');
$model->save(['id' => 10, 'organization_name' => 'CCS', 'username' => 'ccs-edited', 'email' => 'ccs@example.test', 'organization_scope' => 'ALL'], true);
check($pdo->query('SELECT username FROM users WHERE id=10')->fetchColumn() === 'ccs-edited', 'existing organization editing works before the migration');
denied(fn () => $model->delete_account($input, 99), 503, 'only deletion is gated with an actionable setup message before migration');
echo "PASS: organization deletion, authorization, rollback and history preservation.\n";
