<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('PREVENT_DIRECT_ACCESS', true);
define('APP_DIR', dirname(__DIR__) . '/app/');
require APP_DIR . 'libraries/FinesMigrationRunner.php';

exit(FinesMigrationRunner::main($argv, FinesMigrationRunner::definitions()));
