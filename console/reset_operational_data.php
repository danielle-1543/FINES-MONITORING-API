<?php
// CLI-only maintenance utility. Defaults to a read-only inventory.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('PREVENT_DIRECT_ACCESS', true);
require dirname(__DIR__) . '/app/libraries/FinesMigrationRunner.php';
try {
    $connect = new ReflectionMethod(FinesMigrationRunner::class, 'connect');
    $pdo = $connect->invoke(null, dirname(__DIR__));
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $tables = $pdo->query("SELECT TABLE_NAME,ENGINE FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll();
    $foreignKeys = $pdo->query("SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.key_column_usage WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,ORDINAL_POSITION")->fetchAll();
    $counts = []; $columns = [];
    foreach ($tables as $table) {
        $name = $table['TABLE_NAME'];
        $quoted = '`' . str_replace('`', '``', $name) . '`';
        $counts[$name] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn();
        $columns[$name] = $pdo->query('SHOW COLUMNS FROM ' . $quoted)->fetchAll(PDO::FETCH_COLUMN);
    }
    if (in_array('--execute', $argv, true) || in_array('--backup-only', $argv, true)) {
        $backupOnly = in_array('--backup-only', $argv, true);
        if (!in_array('--preserve-admin-and-organizations', $argv, true)) throw new RuntimeException('Explicit protected-account mode is required.');
        $clear = ['xendit_payments', 'payment_claim_items', 'payment_claims', 'student_voucher_items', 'student_vouchers',
            'student_notifications', 'academic_record_changes', 'student_change_history', 'student_qr_credentials',
            'section_format_changes', 'student_enrollments', 'org_violation_records', 'org_scan_records',
            'org_event_roster', 'org_events', 'activity_logs', 'students'];
        $preserve = ['academic_college_programs', 'academic_colleges', 'academic_periods', 'academic_programs',
            'fines_schema_migrations', 'org_violation_types', 'organization_payment_periods', 'organization_programs'];
        $known = array_merge($clear, $preserve, ['users', 'refresh_tokens']);
        if (array_diff(array_keys($counts), $known) || array_diff($known, array_keys($counts))) throw new RuntimeException('Unreviewed database tables detected. Inspect and review the plan again.');
        foreach ($tables as $table) if ($table['ENGINE'] !== 'InnoDB') throw new RuntimeException('All reset tables must support transactions.');
        $quoteName = static fn ($name) => '`' . str_replace('`', '``', $name) . '`';
        $pdo->exec('SET SESSION innodb_lock_wait_timeout=15');
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        if ($backupOnly) $pdo->exec('SET TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        // Lock full index ranges, including empty tables, before taking the backup.
        // Concurrent application writes wait; no FOREIGN_KEY_CHECKS disabling or TRUNCATE.
        $snapshot = []; $ddl = []; $insertColumns = [];
        foreach ($tables as $table) {
            $name = $table['TABLE_NAME'];
            $phase = 'locking ' . $name;
            $snapshot[$name] = $pdo->query('SELECT * FROM ' . $quoteName($name) . ($backupOnly ? '' : ' FOR UPDATE'))->fetchAll();
            $ddl[$name] = $pdo->query('SHOW CREATE TABLE ' . $quoteName($name))->fetch(PDO::FETCH_NUM)[1];
            $metadata = $pdo->query('SHOW COLUMNS FROM ' . $quoteName($name))->fetchAll();
            $insertColumns[$name] = array_column(array_filter($metadata, static fn ($column) => !str_contains($column['Extra'], 'GENERATED')), 'Field');
        }
        if (!$pdo->query("SELECT COUNT(*) FROM users WHERE role='ADMIN' AND status='ACTIVE'")->fetchColumn()) throw new RuntimeException('No active administrator found.');
        if ($pdo->query("SELECT COUNT(*) FROM students s JOIN users u ON u.id=s.user_id WHERE u.role<>'STUDENT'")->fetchColumn()) throw new RuntimeException('A student points to a protected account; reset aborted.');
        // Every foreign-key dependency must have been reviewed and listed.
        foreach ($foreignKeys as $fk) {
            if (in_array($fk['REFERENCED_TABLE_NAME'], $clear, true) && !in_array($fk['TABLE_NAME'], $clear, true)) throw new RuntimeException('Protected table depends on deleted operational data.');
        }
        $phase = 'creating and verifying the full backup';
        $fingerprint = static function (array $rows): string {
            $encoded = array_map(static fn ($row) => json_encode($row, JSON_THROW_ON_ERROR), $rows);
            sort($encoded, SORT_STRING); return hash('sha256', implode("\n", $encoded));
        };
        $protectedUsers = array_values(array_filter($snapshot['users'], static fn ($row) => $row['role'] !== 'STUDENT'));
        $studentUserIds = array_column(array_filter($snapshot['users'], static fn ($row) => $row['role'] === 'STUDENT'), 'id');
        $protectedTokens = array_values(array_filter($snapshot['refresh_tokens'], static fn ($row) => !in_array($row['user_id'], $studentUserIds, true)));
        $directory = dirname(__DIR__) . '/runtime/reset_backups/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6));
        if (!mkdir($directory, 0700, true)) throw new RuntimeException('Unable to create backup directory.');
        $write = static function (string $path, string $data): void {
            if (file_put_contents($path, $data, LOCK_EX) !== strlen($data)) throw new RuntimeException('Backup write failed.');
        };
        $write($directory . '/.htaccess', "Require all denied\n");
        $write($directory . '/index.html', '');
        $backup = ['format' => 'fines-full-database-v1', 'created_at_utc' => gmdate(DATE_ATOM),
            'schema' => $ddl, 'insert_columns' => $insertColumns, 'foreign_keys' => $foreignKeys, 'rows' => $snapshot];
        $json = json_encode($backup, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $write($directory . '/database.json', $json);
        $verified = json_decode(file_get_contents($directory . '/database.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($snapshot as $name => $rows) if ($fingerprint($verified['rows'][$name]) !== $fingerprint($rows)) throw new RuntimeException('Backup verification failed.');
        // Parent-first restore order, derived from the inspected foreign keys.
        $restoreOrder = []; $waiting = array_keys($snapshot);
        while ($waiting) {
            $progress = false;
            foreach ($waiting as $key => $name) {
                $parents = array_column(array_filter($foreignKeys, static fn ($fk) => $fk['TABLE_NAME'] === $name && $fk['REFERENCED_TABLE_NAME'] !== $name), 'REFERENCED_TABLE_NAME');
                if (array_diff($parents, $restoreOrder)) continue;
                $restoreOrder[] = $name; unset($waiting[$key]); $progress = true;
            }
            if (!$progress) throw new RuntimeException('Cannot generate dependency-safe backup restore order.');
        }
        $mode = $pdo->quote($pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn());
        $sql = "-- Full pre-reset backup. Restore ONLY into a separate, empty database.\nSET NAMES utf8mb4;\nSET SESSION sql_mode=$mode;\n";
        foreach ($restoreOrder as $name) {
            $sql .= $ddl[$name] . ";\n";
            $names = $insertColumns[$name];
            foreach ($snapshot[$name] as $row) {
                $values = array_map(static fn ($column) => $row[$column] === null ? 'NULL' : $pdo->quote((string) $row[$column]), $names);
                $sql .= 'INSERT INTO ' . $quoteName($name) . ' (' . implode(',', array_map($quoteName, $names)) . ') VALUES (' . implode(',', $values) . ");\n";
            }
        }
        $write($directory . '/database.sql', $sql);
        if (hash_file('sha256', $directory . '/database.sql') !== hash('sha256', $sql)) throw new RuntimeException('SQL backup checksum failed.');
        $before = array_map('count', $snapshot);
        $manifest = ['created_at_utc' => gmdate(DATE_ATOM), 'status' => 'BACKUP_VERIFIED_NOT_COMMITTED',
            'before_counts' => $before, 'cleared_tables' => $clear, 'preserved_tables' => $preserve,
            'json_sha256' => hash_file('sha256', $directory . '/database.json'), 'sql_sha256' => hash_file('sha256', $directory . '/database.sql')];
        $write($directory . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        if ($backupOnly) {
            $pdo->rollBack();
            $manifest['status'] = 'BACKUP_ONLY_NO_DATABASE_CHANGES';
            $write($directory . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            echo json_encode(['status' => $manifest['status'], 'backup_directory' => $directory, 'counts' => $before], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
            exit;
        }
        // Do not reset auto-increment IDs: old callbacks/QR/notification IDs must not be reused.
        foreach ($clear as $name) { $phase = 'clearing ' . $name; $pdo->exec('DELETE FROM ' . $quoteName($name)); }
        $phase = 'removing student login accounts and sessions';
        $pdo->exec("DELETE rt FROM refresh_tokens rt JOIN users u ON u.id=rt.user_id WHERE u.role='STUDENT'");
        $pdo->exec("DELETE FROM users WHERE role='STUDENT'");
        $phase = 'verifying zero operational records and unchanged protected data';
        $after = [];
        foreach ($tables as $table) {
            $name = $table['TABLE_NAME'];
            $rows = $pdo->query('SELECT * FROM ' . $quoteName($name))->fetchAll();
            $after[$name] = count($rows);
            if (in_array($name, $clear, true) && $rows) throw new RuntimeException('Operational data remains; rolling back.');
            if (in_array($name, $preserve, true) && $fingerprint($rows) !== $fingerprint($snapshot[$name])) throw new RuntimeException('Protected configuration changed; rolling back.');
            if ($name === 'users' && $fingerprint($rows) !== $fingerprint($protectedUsers)) throw new RuntimeException('Protected account mismatch; rolling back.');
            if ($name === 'refresh_tokens' && $fingerprint($rows) !== $fingerprint($protectedTokens)) throw new RuntimeException('Protected login sessions changed; rolling back.');
        }
        $manifest['verified_counts'] = $after;
        $write($directory . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $pdo->commit();
        $manifest['status'] = 'COMMITTED';
        $write($directory . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        echo json_encode(['status' => 'COMMITTED', 'backup_directory' => $directory, 'before_counts' => $before, 'after_counts' => $after,
            'protected_accounts_unchanged' => true, 'protected_configuration_unchanged' => true,
            'roles' => $pdo->query('SELECT role,COUNT(*) count FROM users GROUP BY role')->fetchAll()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit;
    }
    $transactions = [];
    try { $transactions = $pdo->query('SELECT trx_id,trx_started,trx_state,trx_mysql_thread_id,trx_tables_locked,trx_rows_locked,trx_rows_modified FROM information_schema.innodb_trx')->fetchAll(); }
    catch (Throwable $ignored) { $transactions = ['Transaction metadata unavailable to this database user.']; }
    echo json_encode(['read_only' => true, 'transactions' => $transactions, 'tables' => $tables, 'counts' => $counts, 'columns' => $columns, 'foreign_keys' => $foreignKeys,
        'roles' => $pdo->query('SELECT role,COUNT(*) count FROM users GROUP BY role')->fetchAll()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Maintenance stopped' . (isset($phase) ? ' while ' . $phase : '') . ': ' . $error->getMessage() . PHP_EOL); exit(1);
}
