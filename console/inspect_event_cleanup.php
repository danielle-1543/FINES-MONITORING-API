<?php
// Read-only dependency report. This command never deletes or updates records.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('PREVENT_DIRECT_ACCESS', true);
$name = $argv[1] ?? '';
if ($name === '') { fwrite(STDERR, "Usage: php console/inspect_event_cleanup.php \"exact event name\"\n"); exit(1); }
$root = dirname(__DIR__);
try {
    if (is_readable($root . '/.env')) foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (!preg_match('/^\s*([A-Z_][A-Z0-9_]*)\s*=(.*)$/', $line, $match)) continue;
        if (getenv($match[1]) === false) putenv($match[1] . '=' . trim(trim($match[2]), "\"'"));
    }
    require $root . '/app/config/database.php';
    $config = $database['main'];
    $pdo = new PDO("mysql:host={$config['hostname']};port={$config['port']};dbname={$config['database']};charset=utf8mb4", $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 10,
    ]);
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $query = function (string $sql, array $params = []) use ($pdo) { $statement = $pdo->prepare($sql); $statement->execute($params); return $statement; };
    $exists = function (string $table) use ($query): bool {
        return (bool) $query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$table])->fetchColumn();
    };
    $events = $query('SELECT e.id,e.name,e.org_user_id,u.organization_name,e.event_type,e.scan_starts_at,e.scan_ends_at FROM org_events e JOIN users u ON u.id=e.org_user_id WHERE e.name=? ORDER BY e.id', [$name])->fetchAll();
    foreach ($events as &$event) {
        $id = (int) $event['id'];
        $scans = $query('SELECT id FROM org_scan_records WHERE event_id=?', [$id])->fetchAll(PDO::FETCH_COLUMN);
        $violations = $query('SELECT id FROM org_violation_records WHERE event_id=? OR scan_record_id IN (SELECT id FROM org_scan_records WHERE event_id=?)', [$id, $id])->fetchAll(PDO::FETCH_COLUMN);
        $event['scan_records'] = count($scans);
        $event['roster_records'] = (int) $query('SELECT COUNT(*) FROM org_event_roster WHERE event_id=?', [$id])->fetchColumn();
        $event['linked_violations'] = count($violations);
        $keys = array_merge(array_map(fn ($value) => 'scan-' . $value, $scans), array_map(fn ($value) => 'violation-' . $value, $violations));
        $event['payment_claims'] = [];
        $event['voucher_allocations'] = 0;
        $event['notifications'] = 0;
        if ($keys) {
            $marks = implode(',', array_fill(0, count($keys), '?'));
            $claimIds = $query("SELECT id FROM payment_claims WHERE fine_key IN ($marks) UNION SELECT claim_id FROM payment_claim_items WHERE fine_key IN ($marks)", array_merge($keys, $keys))->fetchAll(PDO::FETCH_COLUMN);
            foreach ($claimIds as $claimId) {
                $claim = $query('SELECT id,status,method,amount,receipt_number FROM payment_claims WHERE id=?', [$claimId])->fetch();
                $allocations = $query('SELECT fine_key FROM payment_claim_items WHERE claim_id=?', [$claimId])->fetchAll(PDO::FETCH_COLUMN);
                $claim['allocations_to_other_events'] = count(array_diff($allocations, $keys));
                $event['payment_claims'][] = $claim;
            }
            if ($exists('student_voucher_items')) $event['voucher_allocations'] = (int) $query("SELECT COUNT(*) FROM student_voucher_items WHERE fine_key IN ($marks)", $keys)->fetchColumn();
            if ($exists('student_notifications')) {
                $sources = array_map(fn ($key) => str_replace(['scan-', 'violation-'], ['scan:', 'violation:'], $key), $keys);
                $event['notifications'] = (int) $query("SELECT COUNT(*) FROM student_notifications WHERE source_key IN ($marks)", $sources)->fetchColumn();
            }
        }
        $event['financial_links_present'] = count($event['payment_claims']) > 0 || $event['voucher_allocations'] > 0;
    }
    unset($event);
    $pdo->rollBack();
    echo json_encode(['read_only' => true, 'matching_events' => $events], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Read-only inspection failed (' . get_class($error) . '). Check the configured database connection and schema. No records were changed.' . PHP_EOL);
    exit(1);
}
