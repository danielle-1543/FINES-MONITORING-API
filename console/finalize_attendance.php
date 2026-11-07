<?php
// CLI worker: finalize ended attendance windows every 15 seconds with --watch.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('PREVENT_DIRECT_ACCESS', true);
chdir(dirname(__DIR__));
foreach (file('.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    putenv(trim($key) . '=' . trim(trim($value), "\"'"));
}
require 'app/config/database.php';
function database_config()
{
    global $database;
    return $database;
}
function config_item($key)
{
    return [
        'environment' => 'development',
        'timestamps' => false,
        'soft_delete' => false
    ][$key] ?? null;
}
function lava_instance()
{
    global $attendanceLava;
    return $attendanceLava;
}

class AttendanceCliErrors
{
    public function show_database_error($message, $sql = '', $bindings = [], $exception = null)
    {
        throw $exception ?: new RuntimeException($message);
    }
}
function load_class($class, $directory = '')
{
    return new AttendanceCliErrors();
}
require 'scheme/database/Database.php';
require 'scheme/kernel/Model.php';
require 'app/models/AttendanceModel.php';
$db = new Database();
$attendanceLava = (object) ['db' => $db];
$model = new AttendanceModel();
$watch = in_array('--watch', $argv, true);
do {
    try {
        $count = $model->finalize();
        if ($count) {
            echo date('c') . " Marked {$count} absences.\n";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Attendance finalization failed: " . get_class($e) . "\n");
        if (!$watch) {
            exit(1);
        }
    }
    if ($watch) {
        sleep(15);
    }
}
while ($watch);
