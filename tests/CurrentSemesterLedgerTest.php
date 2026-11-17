<?php
define('PREVENT_DIRECT_ACCESS', true);
require dirname(__DIR__) . '/app/libraries/CurrentSemesterLedger.php';

$periods = [
    ['id' => 10, 'year_start' => 2026, 'term' => 'FIRST', 'label' => '2026 First'],
    ['id' => 2, 'year_start' => 2026, 'term' => 'SECOND', 'label' => '2026 Second'],
    ['id' => 3, 'year_start' => 2027, 'term' => 'FIRST', 'label' => '2027 First']
];
$fine = [
    'fine_key' => 'violation-1', 'academic_period_id' => 10,
    'academic_period_label' => '2026 First', 'fine_amount' => '300.00',
    'payable_amount' => '200.00', 'paid_amount' => '50.00',
    'outstanding' => '150.00', 'pending_amount' => '25.00',
    'available_to_claim' => '125.00', 'cap_adjustment' => '80.00',
    'discount_amount' => '20.00'
];
function check($actual, $expected, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($message);
    }
}
$rows = CurrentSemesterLedger::select([$fine], $periods[1], $periods);
check(count($rows), 1, 'Unpaid previous semester must carry');
check($rows[0]['fine_amount'], '150.00', 'Carry only remaining balance after payments and reductions');
check($rows[0]['fine_key'], 'violation-1', 'Keep original allocation key');
check($rows[0]['pending_amount'], '25.00', 'Preserve pending reservations');
check($rows[0]['available_to_claim'], '125.00', 'Do not charge pending money again');
check($rows[0]['paid_amount'], '0.00', 'Past payments do not inflate new semester totals');
check(CurrentSemesterLedger::select([array_replace($fine, ['outstanding' => '0.00'])], $periods[1], $periods), [], 'Paid fines must stay archived');
check(CurrentSemesterLedger::select([$fine], null, $periods), [], 'No current semester means no current ledger');
check(CurrentSemesterLedger::select([array_replace($fine, ['academic_period_id' => 3])], $periods[1], $periods), [], 'Future semesters must not carry backwards');
check(CurrentSemesterLedger::select([$fine], $periods[0], $periods)[0]['fine_amount'], '300.00', 'Current fines retain their amounts');
check(CurrentSemesterLedger::select([$fine], $periods[2], $periods)[0]['fine_amount'], '150.00', 'Carry across academic years without duplicating debt');
check($fine['academic_period_id'], 10, 'Source record must remain unchanged');
echo "Current semester ledger checks passed.\n";
