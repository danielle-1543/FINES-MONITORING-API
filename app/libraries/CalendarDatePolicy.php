<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

final class CalendarDatePolicy
{
    public static function assertNotPastDay(string $value, string $label, ?DateTimeImmutable $now = null): void
    {
        $zone = new DateTimeZone('Asia/Manila');
        $day = substr($value, 0, 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, $zone);
        if (!$date || $date->format('Y-m-d') !== $day) {
            throw new DomainException($label . ' must be a valid date.', 422);
        }
        $today = ($now ?? new DateTimeImmutable('now', $zone))->setTimezone($zone)->format('Y-m-d');
        if ($day < $today) {
            throw new DomainException($label . ' cannot be in the past. Choose today or a later date (Philippine time).', 422);
        }
    }
}
