<?php
// Isolated SQLite fixtures and the real LavaLust Query Builder. No .env or providers.
require __DIR__ . '/test_academic_periods.php';
$current = $academic->current();
$pdo->exec("UPDATE students SET year_level='4th Year' WHERE id=90;
    INSERT INTO students(id,student_number,full_name,section,course,year_level,account_status,activation_status,current_academic_period_id)
    VALUES(9900,'DEMO-NOT-GRADUATING','Not Fourth Year','F3','BSIT','3rd Year','ACTIVE','PENDING'," . $current['id'] . ")");
$graduating = ['student_ids' => [90], 'reason' => 'GRADUATING', 'changes' => [], 'preview' => true];
denied(fn () => $studentsModel->update_students(array_replace($graduating, ['student_ids' => [90,9900]]), 99), 409, 'Graduating update rejects mixed fourth/non-fourth selections');
$review = $studentsModel->update_students($graduating, 99);
check($pdo->query('SELECT year_level FROM students WHERE id=90')->fetchColumn() === '4th Year', 'Graduating preview changes no student before confirmation');
$studentsModel->update_students(array_replace($graduating, ['preview' => false, 'fingerprint' => $review['fingerprint']]), 99);
check($pdo->query('SELECT year_level FROM students WHERE id=90')->fetchColumn() === 'Graduating', 'confirmed profile update marks selected fourth-year student Graduating');
$withinYear = saveAcademic($academic, ['year_start' => $current['year_start'], 'term' => 'SECOND', 'is_current' => true]);
check($withinYear['graduates_automatic'] === 0 && $pdo->query('SELECT year_level FROM students WHERE id=90')->fetchColumn() === 'Graduating', 'semester-only change keeps Graduating status and moves student normally');
$firstSnapshot = $academic->semester_history()[0];
$graduatingExport = array_column($academic->semester_export((int) $firstSnapshot['id'])['data'], null, 'student_number');
check($graduatingExport['DEMO-NOHISTORY']['student_status'] === 'Graduating' && $graduatingExport['DEMO-NOHISTORY']['year_level'] === '4th Year', 'same-year saved export keeps year level separate from Graduating status');
$nextYear = ['year_start' => (int) $current['year_start'] + 1, 'term' => 'FIRST', 'is_current' => true];
$preview = $academic->save($nextYear + ['preview' => true], 99);
check($preview['graduates_automatic'] === 1 && $pdo->query('SELECT year_level FROM students WHERE id=90')->fetchColumn() === 'Graduating', 'academic-year confirmation previews automatic graduation without changing students');
$pdo->exec("CREATE TRIGGER fail_automatic_graduation BEFORE INSERT ON student_change_history WHEN NEW.reason='AUTO_GRADUATED' BEGIN SELECT RAISE(ABORT,'simulated graduation failure'); END");
try {
    $academic->save($nextYear + ['preview' => false, 'fingerprint' => $preview['fingerprint']], 99);
    throw new RuntimeException('Graduation failure unexpectedly committed.');
} catch (PDOException $expected) {
    check((int) $academic->current()['id'] === $withinYear['id'] && $pdo->query('SELECT year_level FROM students WHERE id=90')->fetchColumn() === 'Graduating', 'graduation audit failure rolls back semester and student changes together');
}
$pdo->exec('DROP TRIGGER fail_automatic_graduation');
$preserved = [];
foreach (['payment_claims','payment_claim_items','student_notifications','org_scan_records','org_violation_records'] as $table) $preserved[$table] = $pdo->query('SELECT * FROM ' . $table)->fetchAll();
$advanced = saveAcademic($academic, $nextYear);
$graduate = $pdo->query('SELECT * FROM students WHERE id=90')->fetch();
check($advanced['graduates_automatic'] === 1 && $graduate['year_level'] === 'Graduated'
    && (int) $graduate['current_academic_period_id'] === $withinYear['id'], 'new academic year automatically graduates candidates and retains final semester assignment');
check(!in_array(90, $studentsModel->matching_student_ids([], false), true), 'automatically graduated students leave the default current-student list');
$snapshot = $academic->semester_history()[0];
$frozenExport = $academic->semester_export((int) $snapshot['id']);
$exportRows = array_column($frozenExport['data'], null, 'student_number');
check($exportRows['DEMO-NOHISTORY']['student_status'] === 'Graduated' && $exportRows['DEMO-NOHISTORY']['year_level'] === '4th Year', 'final semester export includes automatic graduates and their completed year level');
$restored = saveAcademic($academic, array_replace($academic->period((int) $current['id']), ['is_current' => true, 'status' => 'OPEN']));
check($restored['graduating_restored'] === 1 && $pdo->query('SELECT year_level FROM students WHERE id=90')->fetchColumn() === 'Graduating', 'reopening an earlier semester where the student was Graduating restores that status');
check($academic->semester_export((int) $snapshot['id']) === $frozenExport, 'returning to an old semester does not rewrite frozen exports');
check($pdo->query('SELECT year_level FROM students WHERE id=71')->fetchColumn() === 'Graduated', 'manual graduates stay archived during backward switches');
saveAcademic($academic, array_replace($academic->period($advanced['id']), ['is_current' => true, 'status' => 'OPEN']));
check($pdo->query('SELECT year_level FROM students WHERE id=90')->fetchColumn() === 'Graduated', 'returning forward graduates a restored candidate again');
$beforeDelete = $pdo->query('SELECT * FROM student_change_history')->fetchAll();
denied(fn () => $academic->delete_semester_history(['id' => $snapshot['id']], 99), 422, 'saved export deletion requires explicit confirmation');
$academic->delete_semester_history(['id' => $snapshot['id'], 'confirmed' => true], 99);
check(!in_array($snapshot['id'], array_column($academic->semester_history(), 'id')), 'deleted saved export disappears from history');
denied(fn () => $academic->semester_export((int) $snapshot['id']), 404, 'deleted saved export cannot be downloaded');
$deleted = $pdo->query('SELECT * FROM academic_record_changes WHERE id=' . (int) $snapshot['id'])->fetch();
$details = json_decode($deleted['change_details'], true);
check($deleted['source_kind'] === 'SEMESTER_DELETED' && $details['deleted_by'] === 99 && $details['semester_students'] === $snapshot['snapshot']['semester_students'], 'deletion retains original snapshot and administrator audit without a schema change');
check($pdo->query('SELECT * FROM student_change_history')->fetchAll() === $beforeDelete, 'deleting a saved export preserves graduation and year-level history');
$academic->delete_semester_history(['id' => $snapshot['id'], 'confirmed' => true], 99);
saveAcademic($academic, array_replace($academic->period($withinYear['id']), ['is_current' => true, 'status' => 'OPEN']));
check($pdo->query('SELECT year_level FROM students WHERE id=90')->fetchColumn() === 'Graduating', 'Graduating restoration still works after deleting its saved export');
foreach ($preserved as $table => $rows) check($pdo->query('SELECT * FROM ' . $table)->fetchAll() === $rows, 'graduation, restoration and export deletion preserve ' . $table);
echo "PASS: Graduating rollover, restoration, frozen exports and safe deletion.\n";
