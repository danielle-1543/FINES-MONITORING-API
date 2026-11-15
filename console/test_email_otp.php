<?php
// Isolated SQLite fixtures. Never sends email or connects to the application DB.
define('PREVENT_DIRECT_ACCESS', true);
function config_item($key) { return ['timestamps'=>false, 'soft_delete'=>false][$key] ?? null; }
function load_class($name, $dir = '') { return new class {
    public function show_database_error($message, $sql = '', $bindings = [], $error = null) { throw $error ?: new RuntimeException($message); }
}; }
function lava_instance() { return $GLOBALS['otpTestLava']; }
require dirname(__DIR__).'/scheme/database/Database.php';
require dirname(__DIR__).'/scheme/kernel/Model.php';
require dirname(__DIR__).'/app/models/EmailOtpModel.php';
class OtpFixtureDatabase extends Database {
    public function raw($query, $args = []) { return parent::raw(str_replace([' FOR UPDATE','INSERT IGNORE'], ['', 'INSERT OR IGNORE'], $query), $args); }
}
class OtpFixtureModel extends EmailOtpModel {
    public string $code = '';
    public bool $fail = false;
    protected function deliver(string $email, string $code): void {
        if ($this->fail) throw new DomainException('Simulated delivery failure.',503);
        $this->code = $code;
    }
}
function check($ok, string $label): void { if (!$ok) throw new RuntimeException('FAIL: '.$label); echo 'PASS: '.$label.PHP_EOL; }
function denied(callable $work, int $status, string $label): void {
    try { $work(); } catch (DomainException $e) { check($e->getCode() === $status, $label); return; }
    throw new RuntimeException('FAIL: '.$label.' accepted');
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE email_otps(id TEXT PRIMARY KEY,subject_key TEXT UNIQUE,user_id INTEGER,email TEXT,purpose TEXT,code_hash TEXT,expires_at INTEGER,created_at INTEGER,attempts INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT,password_hash TEXT,status TEXT)');
$db = (new ReflectionClass(OtpFixtureDatabase::class))->newInstanceWithoutConstructor();
foreach (['db'=>$pdo,'driver'=>'sqlite','db_prefix'=>''] as $key=>$value) (new ReflectionProperty(Database::class,$key))->setValue($db,$value);
$GLOBALS['otpTestLava'] = (object)['db'=>$db];
$model = new OtpFixtureModel();
$challenge = $model->issue('Test@example.com','signup');
$code = $model->code;
check((bool)preg_match('/^[0-9]{6}$/D',$code), 'six-digit code');
$row = $pdo->query('SELECT * FROM email_otps')->fetch();
check($row['code_hash'] !== $code && password_verify($code,$row['code_hash']), 'only a password hash is stored');
denied(fn()=>$model->issue('test@example.com','signup'),429,'resend cooldown');
$data = $challenge + ['otp'=>$code];
denied(fn()=>$model->consume($data,'password_reset','test@example.com'),422,'purpose isolation');
denied(fn()=>$model->consume($data,'signup','other@example.com'),422,'email isolation');
denied(fn()=>$model->consume($data,'signup','test@example.com',2),422,'user isolation');
$model->consume($data,'signup','test@example.com');
denied(fn()=>$model->consume($data,'signup','test@example.com'),422,'single-use code');
$challenge = $model->issue('test@example.com','password_change',1);
$data = $challenge + ['otp'=>$model->code];
$wrong = $model->code === '000000' ? '111111' : '000000';
for ($i=0;$i<5;$i++) denied(fn()=>$model->consume($challenge+['otp'=>$wrong],'password_change','test@example.com',1),422,'incorrect attempt '.($i+1));
denied(fn()=>$model->consume($data,'password_change','test@example.com',1),422,'correct code rejected after five attempts');
check((int)$pdo->query('SELECT attempts FROM email_otps')->fetchColumn() === 6,'attempts persist after failures');
$pdo->exec('UPDATE email_otps SET created_at=0');
$new = $model->issue('test@example.com','password_change',1);
denied(fn()=>$model->consume($data,'password_change','test@example.com',1),422,'resend invalidates previous challenge');
$data = $new + ['otp'=>$model->code];
$pdo->exec('UPDATE email_otps SET expires_at=0');
denied(fn()=>$model->consume($data,'password_change','test@example.com',1),422,'expired code rejected');
$model->fail = true;
denied(fn()=>$model->issue('failure@example.com','signup'),503,'delivery failure surfaced');
check((int)$pdo->query("SELECT COUNT(*) FROM email_otps WHERE email='failure@example.com'")->fetchColumn() === 0,'failed delivery leaves no usable code');
$model->fail = false;
$stmt=$pdo->prepare("INSERT INTO users VALUES(1,'test@example.com',?,'ACTIVE')"); $stmt->execute([password_hash('CurrentPassword1',PASSWORD_DEFAULT)]);
$body=['current_password'=>'wrong','email'=>'new@example.com'];
denied(function () use ($model,&$body) { $model->protect(1,$body,false); },403,'current password required');
$body=['current_password'=>'CurrentPassword1','email'=>'new@example.com'];
denied(function () use ($model,&$body) { $model->protect(1,$body,false); },422,'email update cannot bypass OTP');
$body=['current_password'=>'CurrentPassword1'];
denied(function () use ($model,&$body) { $model->protect(1,$body,true); },422,'password update cannot bypass OTP');
$challenge=$model->issue('new@example.com','email_change',1);
$body=['current_password'=>'CurrentPassword1','email'=>'new@example.com','otp'=>$model->code]+$challenge;
// The real endpoints receive only challenge_id, not the delivery metadata.
unset($body['expires_in'],$body['resend_after']);
$model->protect(1,$body,false);
check(!isset($body['otp']) && !isset($body['challenge_id']),'verified transport fields removed before account validation');
echo "Email OTP security tests passed.\n";
