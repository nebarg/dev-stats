<?php

namespace App\Actions\Summaries;

use App\Models\SummaryItem;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Builds `summary_items` rows: one per (day, type, key) with the total seconds
 * of that bucket. Sessions never cross a day boundary, so bucketing by start
 * time is exact. Null and empty dimension values share the null-key bucket.
 */
class SummaryItemRows
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function for(User $user, CarbonImmutable $start, CarbonImmutable $end, string $timezone): array
    {
        $totals = [];

        $durations = $user->durations()
            ->startedBetween($start, $end)
            ->lazy();

        foreach ($durations as $duration) {
            $day = $duration->started_at->setTimezone($timezone)->toDateString();

            foreach (SummaryItem::TYPES as $type) {
                $totals[$day][$type][$duration->{$type} ?? ''] ??= 0;
                $totals[$day][$type][$duration->{$type} ?? ''] += $duration->duration_seconds;
            }
        }

        $now = CarbonImmutable::now();
        $rows = [];

        foreach ($totals as $day => $types) {
            foreach ($types as $type => $keys) {
                foreach ($keys as $key => $seconds) {
                    $rows[] = [
                        'user_id' => $user->id,
                        'day' => $day,
                        'type' => $type,
                        'key' => $key === '' ? null : (string) $key,
                        'total_seconds' => $seconds,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        return $rows;
    }
}
