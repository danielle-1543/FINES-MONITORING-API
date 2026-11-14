<?php
// Uses isolated fixtures only; no live database or push-provider calls.
require __DIR__ . '/test_payment_windows.php';
$pdo->exec('ALTER TABLE student_notifications ADD read_at TEXT');
$before = $notifications->inbox(101, 1);
check(count($before['data']) > 0 && $before['unread_count'] === $before['pagination']['total'], 'new inbox rows are unread');
$id = (int) $before['data'][0]['id'];
$notifications->mark_read(101, ['id' => $id]);
$read = $notifications->inbox(101, 1, 'read');
$unread = $notifications->inbox(101, 1, 'unread');
check($read['pagination']['total'] === 1 && (int) $read['data'][0]['id'] === $id
    && $unread['pagination']['total'] === $before['pagination']['total'] - 1,
    'All, Unread and Read queries filter the authenticated inbox independently');
for ($i = 0; $i < 25; $i++) $pdo->prepare('INSERT INTO student_notifications(user_id,source_key,title,message,kind,recorded_at,created_at) VALUES(?,?,?,?,?,?,?)')
    ->execute([101, 'inbox-test-' . $i, 'Attendance recorded', 'No fine charged.', 'PRESENT', '2026-11-14 12:00:00', '2026-11-14 12:00:00']);
$paged = $notifications->inbox(101, 999, 'unread');
check($paged['pagination']['pages'] > 1 && $paged['pagination']['page'] === $paged['pagination']['pages']
    && count($paged['data']) > 0 && count($paged['data']) <= 20, 'filter pagination includes matching records beyond the first page and clamps stale pages');
$otherBefore = $notifications->unread_count(102);
$notifications->mark_read(101, ['all' => true]);
check($notifications->inbox(101, 1, 'unread')['pagination']['total'] === 0
    && $notifications->unread_count(102) === $otherBefore, 'Mark all as read updates only the authenticated student');
denied(fn () => $notifications->mark_read(102, ['id' => $id]), 404, 'students cannot mark another inbox as read');
denied(fn () => $notifications->inbox(101, 1, 'invalid'), 422, 'unsupported inbox filters are rejected');
echo "PASS: filtered notification inbox uses the real LavaLust Query Builder.\n";
