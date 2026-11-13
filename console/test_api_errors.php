<?php
// Isolated error-path checks; no database or application secrets required.
$root = dirname(__DIR__);
$bootstrap = 'define("PREVENT_DIRECT_ACCESS", true); $_SERVER["REQUEST_URI"]="/api/org/dashboard"; '
    . 'require ' . var_export($root . '/scheme/kernel/Routine.php', true) . '; '
    . 'require ' . var_export($root . '/scheme/kernel/Errors.php', true) . '; '
    . 'register_shutdown_function(function () { fwrite(STDERR, "STATUS=" . http_response_code()); }); ';
$cases = [
    'uncaught exception' => ['_exception_handler(new RuntimeException("private exception details"));', 500],
    'missing route' => ['(new Errors())->show_404("Missing", "private path", "");', 404],
    'framework error' => ['(new Errors())->show_error("Error", "private details", "error_general");', 500],
    'database error' => ['(new Errors())->show_database_error("private SQL error");', 500],
    'fatal PHP error' => ['_error_handler(E_USER_ERROR, "private fatal details", __FILE__, 1);', 500],
    'partial output' => ['ob_start(); echo "<!DOCTYPE html>private"; _exception_handler(new RuntimeException("private"));', 500],
];
foreach ($cases as $name => [$code, $status]) {
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-r', $bootstrap . $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]);
    $log = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    $data = json_decode($output, true);
    if (!is_array($data) || ($data['success'] ?? true) !== false || ($data['status'] ?? null) !== $status
        || empty($data['request_id']) || strpos($output, 'private') !== false
        || strpos($log, 'STATUS=' . $status) === false || strpos($log, $data['request_id']) === false) {
        fwrite(STDERR, 'FAIL: ' . $name . PHP_EOL . $output . PHP_EOL . $log);
        exit(1);
    }
    echo 'PASS: ' . $name . PHP_EOL;
}
