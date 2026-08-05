<?php

namespace App\Actions\Summaries;

use App\Models\DailyMetric;
use App\Models\SummaryItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rolls a user's durations into per-day `summary_items` and their heartbeats
 * into per-day `daily_metrics`, resuming from `summaries_generated_until`.
 *
 * Only whole past days are persisted — today is always computed live on read.
 * Days are calendar days in the user's timezone; the row builders bucket in PHP
 * so boundaries land identically on SQLite (tests) and MySQL (prod).
 */
class GenerateSummaries
{
    public function __construct(
        private readonly SummaryItemRows $summaryItems,
        private readonly DailyMetricRows $dailyMetrics,
    ) {}

    /**
     * @return array{days: int, items: int, metrics: int}
     */
    public function forUser(User $user): array
    {
        $timezone = $user->timezone;
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $start = $this->firstUnsummarisedDay($user, $timezone);
        $end = $today->subDay();

        if ($start === null || $start->greaterThan($end)) {
            return ['days' => 0, 'items' => 0, 'metrics' => 0];
        }

        $items = $this->summaryItems->for($user, $start, $end, $timezone);
        $metrics = $this->dailyMetrics->for($user, $start, $end, $timezone);

        DB::transaction(static function () use ($user, $start, $end, $items, $metrics): void {
            $clear = static fn ($query) => $query->forUser($user)->forDayRange($start, $end)->delete();

            $clear(SummaryItem::query());
            $clear(DailyMetric::query());

            foreach (array_chunk($items, 500) as $chunk) {
                SummaryItem::insert($chunk);
            }

            foreach (array_chunk($metrics, 500) as $chunk) {
                DailyMetric::insert($chunk);
            }

            $user->summaries_generated_until = $end;
            $user->save();
        });

        return [
            'days' => (int) $start->diffInDays($end) + 1,
            'items' => count($items),
            'metrics' => count($metrics),
        ];
    }

    /**
     * The day after the last summarised one, or the day of the user's first
     * heartbeat when nothing has been summarised yet. Null without heartbeats.
     */
    private function firstUnsummarisedDay(User $user, string $timezone): ?CarbonImmutable
    {
        if ($user->summaries_generated_until !== null) {
            return CarbonImmutable::parse($user->summaries_generated_until->toDateString(), $timezone)->addDay();
        }

        $first = $user->heartbeats()->min('recorded_at');

        return $first !== null
            ? CarbonImmutable::parse($first, 'UTC')->setTimezone($timezone)->startOfDay()
            : null;
    }
}
