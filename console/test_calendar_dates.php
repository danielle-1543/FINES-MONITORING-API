<?php
define('PREVENT_DIRECT_ACCESS', true);
require dirname(__DIR__) . '/app/libraries/CalendarDatePolicy.php';
// These validation paths must reject invalid input before touching the database.
class Model {}
require dirname(__DIR__) . '/app/models/OrgEventModel.php';
require dirname(__DIR__) . '/app/models/PaymentPeriodModel.php';

function rejectsPast(callable $operation): void {
    try { $operation(); } catch (DomainException $error) {
        if ($error->getCode() === 422) return;
        throw $error;
    }
    throw new RuntimeException('Past date was accepted.');
}
$now = new DateTimeImmutable('2026-11-13T16:00:00+00:00');
rejectsPast(fn () => CalendarDatePolicy::assertNotPastDay('2026-11-13', 'Date', $now));
CalendarDatePolicy::assertNotPastDay('2026-11-14', 'Date', $now);
CalendarDatePolicy::assertNotPastDay('2026-11-15 00:00:00', 'Date', $now);
rejectsPast(fn () => CalendarDatePolicy::assertNotPastDay('2026-02-30', 'Date', $now));
$zone = new DateTimeZone('Asia/Manila');
$today = new DateTimeImmutable('today', $zone);
$yesterday = $today->modify('-1 day')->format('Y-m-d');
$tomorrow = $today->modify('+1 day')->format('Y-m-d');
$event = new OrgEventModel();
$validate = new ReflectionMethod(OrgEventModel::class, 'event_data');
rejectsPast(fn () => $validate->invoke($event, 1, [
    'name' => 'Event', 'event_type' => 'ATTENDANCE',
    'scan_starts_at' => $yesterday . 'T08:00', 'scan_ends_at' => $tomorrow . 'T17:00'
]));
$values = $validate->invoke($event, 1, [
    'name' => 'Event', 'event_type' => 'ATTENDANCE',
    'scan_starts_at' => $today->format('Y-m-d') . 'T08:00', 'scan_ends_at' => $tomorrow . 'T17:00'
]);
if (substr($values['scan_starts_at'], 0, 10) !== $today->format('Y-m-d')) throw new RuntimeException('Today was rejected.');
rejectsPast(fn () => (new PaymentPeriodModel())->save(1, [
    'opens_at' => $yesterday . 'T08:00', 'closes_at' => $tomorrow . 'T17:00'
]));
echo "PASS: Philippine midnight, today/future dates, invalid dates, event and payment API rejection before database access.\n";
