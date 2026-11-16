<?php
// Isolated fixtures only; no hosted database/provider calls.
require __DIR__ . '/test_archive_search.php';
$pdo->exec("INSERT INTO academic_periods(year_start,term,label,status,is_current) VALUES(2030,'FIRST','2030–2031 · First Semester','CLOSED',0)");
$exportPeriod = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO students(id,student_number,full_name,section,course,year_level,current_academic_period_id) VALUES
    (9901,'CURRENT-9901','Current edited name','F9','BSCS','4th Year'," . $academic->current()['id'] . "),
    (9902,'CURRENT-9902','Current graduate','F9','BSCS','Graduated'," . $academic->current()['id'] . "),
    (9904,'CURRENT-9904','No saved profile','F9','BSCS','4th Year'," . $academic->current()['id'] . ");
    INSERT INTO org_events(id,org_user_id,name,academic_period_id,event_type) VALUES(9901,10,'Archive attendance',$exportPeriod,'ATTENDANCE');
    INSERT INTO org_scan_records(id,student_id,event_id,record_type,fine_amount,recorded_at,academic_period_id,legacy_assessment,attendance_status)
        VALUES(9901,9901,9901,'ATTENDANCE','50.00','2030-01-02 09:00:00',$exportPeriod,0,'ABSENT');
    INSERT INTO org_violation_records(id,student_id,org_user_id,violation_name,fine_amount,recorded_at,academic_period_id,legacy_assessment) VALUES
        (9901,9901,10,'Archive violation','70.00','2030-01-01 09:00:00',$exportPeriod,0),
        (9902,9902,10,'Paid violation','30.00','2030-01-01 09:00:00',$exportPeriod,0),
        (9904,9904,10,'Unpaid violation','40.00','2030-01-01 09:00:00',$exportPeriod,0),
        (9905,9901,20,'Other organization','9000.00','2030-01-01 09:00:00',$exportPeriod,0);
    INSERT INTO organization_payment_periods(id,org_user_id,academic_period_id,label,semester_start,semester_end,opens_at,closes_at,cap_amount)
        VALUES(9901,10,$exportPeriod,'2030–2031 · First Semester','1000-01-01','9999-12-31','2000-01-01','2099-01-01','100.00');");
foreach ([[9901,'violation-9901','20.00','CONFIRMED','2030-02-01 10:00:00'],
    [9901,'scan-9901','10.00','PENDING',null], [9901,'violation-9901','99.00','CANCELLED','2030-03-01 10:00:00'],
    [9902,'violation-9902','30.00','CONFIRMED','2030-02-03 10:00:00'],
    [9904,'violation-9904','40.00','CANCELLED','2030-04-01 10:00:00']] as [$sid,$key,$amount,$state,$reviewed]) {
    $pdo->prepare("INSERT INTO payment_claims(student_id,org_user_id,fine_key,amount,method,status,reviewed_at,academic_period_id) VALUES(?,10,?,?,'CASH',?,?,?)")
        ->execute([$sid,$key,$amount,$state,$reviewed,$exportPeriod]);
}
$historic = [
    ['id'=>9901,'student_number'=>'MCC-2030-00001','full_name'=>'Saved Student','course'=>'BSIT','year_level'=>'2nd Year','section'=>'F2','current_academic_period_id'=>$exportPeriod],
    ['id'=>9902,'student_number'=>'MCC-2030-00002','full_name'=>'Saved Graduate','course'=>'BSIT','year_level'=>'4th Year','section'=>'F4','current_academic_period_id'=>$exportPeriod],
];
$pdo->prepare("INSERT INTO academic_record_changes(changed_by,academic_period_id,source_kind,source_id,change_details,changed_at) VALUES(99,?,'SEMESTER_CHANGE',?,?,'2031-01-01 00:00:00')")
    ->execute([$academic->current()['id'], $exportPeriod, json_encode(['from'=>['id'=>$exportPeriod], 'semester_students'=>$historic])]);
$snapshotId = (int) $pdo->lastInsertId();
$exportAuth = ['role'=>'ORGANIZATION','sub'=>10];
$selection = ['academic_period_id'=>(string)$exportPeriod,'org_user_id'=>'10'];
$export = $archive->semester_student_export($exportAuth, $selection);
$rows = array_column($export['data'], null, 'student_number');
check(count($rows) === 3 && $rows['MCC-2030-00001']['course'] === 'BSIT' && $rows['MCC-2030-00001']['year_level'] === '2nd Year'
    && $rows['MCC-2030-00001']['section'] === 'F2', 'semester export uses saved student profile instead of current course/year/section');
$partial = $rows['MCC-2030-00001'];
check($partial['violation_fines'] === '70.00' && $partial['absence_fines'] === '30.00' && $partial['total_fine'] === '100.00'
    && $partial['outstanding'] === '80.00' && $partial['status'] === 'PARTIALLY_PAID' && $partial['paid_at'] === '',
    'one student row combines categories under the cap and ignores pending/cancelled payments for balance and Paid At');
check($rows['MCC-2030-00002']['status'] === 'PAID' && $rows['MCC-2030-00002']['outstanding'] === '0.00'
    && $rows['MCC-2030-00002']['paid_at'] === '2030-02-03 10:00:00', 'fully paid student gets the confirmed verification date');
check($rows['CURRENT-9904']['status'] === 'UNPAID' && $rows['CURRENT-9904']['paid_at'] === ''
    && $rows['CURRENT-9904']['year_level'] === 'Not recorded', 'unpaid students stay in the export and unknown historical profile details are labelled honestly');
check($archive->semester_student_export(['role'=>'ADMIN','sub'=>99], $selection) === $export, 'organization and administrator export use identical scoped financial rules');
$academic->delete_semester_history(['id'=>$snapshotId,'confirmed'=>true],99);
check($archive->semester_student_export($exportAuth,$selection) === $export, 'removing an admin export entry does not change underlying historical fine report');
$allMatching = $archive->semester_student_export($exportAuth,['academic_period_id'=>(string)$second,'search'=>'ARCHIVE-SECTION']);
check(count($allMatching['data']) === 12, 'semester export includes all matching students beyond the ten-row UI page');
denied(fn () => $archive->semester_student_export($exportAuth,array_replace($selection,['org_user_id'=>'20'])),403,'organization cannot export another organization records');
denied(fn () => $archive->semester_student_export(['role'=>'STUDENT','sub'=>1],$selection),403,'student cannot access organization semester exports');
echo "PASS: one-row-per-student semester export, historical profiles, caps and settlement dates.\n";
