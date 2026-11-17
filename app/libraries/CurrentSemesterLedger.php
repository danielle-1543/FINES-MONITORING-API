<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

final class CurrentSemesterLedger
{
    public static function select(array $rows, ?array $current, array $periods): array
    {
        if (!$current) {
            return [];
        }
        $periodMap = array_column($periods, null, 'id');
        $terms = ['FIRST' => 1, 'SECOND' => 2, 'SUMMER' => 3];
        $rank = static fn ($period) => [(int) $period['year_start'], $terms[$period['term']] ?? 0];
        $result = [];
        foreach ($rows as $row) {
            $sourceId = (int) ($row['academic_period_id'] ?? 0);
            $row['is_carried_balance'] = false;
            if ($sourceId !== (int) $current['id']) {
                $source = $periodMap[$sourceId] ?? null;
                if (($sourceId && (!$source || $rank($source) >= $rank($current)))
                    || (float) $row['outstanding'] <= 0) {
                    continue;
                }
                // Keep the original fine key: payments settle the original debt once.
                $row['is_carried_balance'] = true;
                $row['source_academic_period_id'] = $sourceId;
                $row['source_academic_period_label'] = $row['academic_period_label'] ?? 'Legacy / Unassigned';
                $row['academic_period_id'] = (int) $current['id'];
                $row['academic_period_label'] = $current['label'];
                $row['fine_amount'] = $row['outstanding'];
                $row['payable_amount'] = $row['outstanding'];
                $row['paid_amount'] = '0.00';
                $row['cap_adjustment'] = '0.00';
                $row['discount_amount'] = '0.00';
            }
            $result[] = $row;
        }
        return $result;
    }
}
