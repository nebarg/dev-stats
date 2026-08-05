<?php

namespace App\Stats;

use Carbon\CarbonImmutable;

/**
 * Average coding seconds per weekday — total time on that weekday divided by how
 * often it occurs in the window — with the `ai coding` share broken out so the
 * bars can stack an AI portion.
 */
class WeekdayAverages
{
    /**
     * @var array<int, string>
     */
    private const array LABELS = [1 => 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    /**
     * @param  array<string, int>  $perDay
     * @param  array<string, int>  $aiPerDay
     * @return array<int, array{label: string, average_seconds: int, ai_average_seconds: int}>
     */
    public function calculate(array $perDay, array $aiPerDay, CarbonImmutable $from, CarbonImmutable $dataEnd): array
    {
        $totals = array_fill(1, 7, 0);
        $aiTotals = array_fill(1, 7, 0);
        $occurrences = array_fill(1, 7, 0);

        for ($day = $from; $day <= $dataEnd; $day = $day->addDay()) {
            $weekday = $day->dayOfWeekIso;
            $date = $day->toDateString();

            $occurrences[$weekday]++;
            $totals[$weekday] += $perDay[$date] ?? 0;
            $aiTotals[$weekday] += $aiPerDay[$date] ?? 0;
        }

        return collect(self::LABELS)
            ->map(static fn (string $label, int $weekday): array => [
                'label' => $label,
                'average_seconds' => intdiv($totals[$weekday], max(1, $occurrences[$weekday])),
                'ai_average_seconds' => intdiv($aiTotals[$weekday], max(1, $occurrences[$weekday])),
            ])
            ->values()
            ->all();
    }
}
