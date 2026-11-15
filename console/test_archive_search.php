<?php
// Real LavaLust Query Builder with isolated SQLite fixtures only.
require __DIR__ . '/test_academic_periods.php';
for ($i = 1; $i <= 12; $i++) {
    $studentId = 9000 + $i; $scanId = 9000 + $i;
    $pdo->prepare("INSERT INTO students(id,student_number,full_name,section,course,year_level,account_status,activation_status,current_academic_period_id) VALUES(?,?,?,'ARCHIVE-SECTION','BSIT','2nd Year','ACTIVE','PENDING',?)")
        ->execute([$studentId, 'ARCHIVE-ID-' . $i, 'Archive Person ' . $i, $second]);
    $pdo->prepare("INSERT INTO org_scan_records(id,student_id,event_id,record_type,fine_amount,recorded_at,academic_period_id,legacy_assessment,attendance_status) VALUES(?,?,2,'ATTENDANCE','40.00','2026-11-15 10:00:00',?,0,'ABSENT')")
        ->execute([$scanId, $studentId, $second]);
    $pdo->prepare("INSERT INTO payment_claims(student_id,org_user_id,fine_key,amount,method,reference_number,submitted_at,status,academic_period_id) VALUES(?,10,?,'20.00','CASH',?,'2026-11-15 11:00:00','CONFIRMED',?)")
        ->execute([$studentId, 'scan-' . $scanId, 'ARCHIVE-REF-' . $i, $second]);
}
$auth = ['role' => 'ORGANIZATION', 'sub' => 10];
$filters = ['academic_period_id' => (string) $second, 'search' => 'archive-section'];
$report = $archive->report($auth, $filters);
check(count($report['fines']) === 10 && count($report['student_payments']) === 10
    && $report['pagination']['total'] === 12 && $report['student_payment_pagination']['pages'] === 2,
    'archive search matches section case-insensitively and displays at most ten fines and student payments');
$last = $archive->report($auth, $filters + ['page' => 2, 'payment_page' => 2]);
check(count($last['fines']) === 2 && count($last['student_payments']) === 2, 'second archive pages retain remaining matching records');
$export = $archive->report($auth, $filters + ['export' => '1']);
check(count($export['fines']) === 12 && count($export['student_payments']) === 12, 'exports retain every matching record beyond the ten-row display limit');
$number = $archive->report($auth, ['search' => 'ARCHIVE-ID-12']);
$name = $archive->report($auth, ['search' => 'archive person 12']);
check(count($number['student_payments']) === 1 && $number['student_payments'] === $name['student_payments'], 'student number and name searches find the same student');
check(!$archive->report(['role' => 'ORGANIZATION', 'sub' => 20], $filters)['fines'], 'archive search cannot reveal another organization records');
check(!$archive->report($auth, ['search' => '%_'])['fines'], 'search treats SQL wildcard characters as literal text');
denied(fn () => $archive->report($auth, ['search' => str_repeat('a', 151)]), 422, 'overlong search terms are rejected');
echo "PASS: archive search, organization scope and ten-row pagination.\n";
