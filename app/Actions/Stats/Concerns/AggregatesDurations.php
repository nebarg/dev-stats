<?php

namespace App\Actions\Stats\Concerns;

use App\Models\Duration;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Range resolution and duration aggregation shared by the stats builders.
 * Day-bucketed aggregation is done in PHP (not SQL) so buckets land in the
 * user's timezone and the logic stays identical on SQLite (tests) and MySQL
 * (prod).
 */
trait AggregatesDurations
{
    public const string DEFAULT_RANGE = '7d';

    private const int BREAKDOWN_LIMIT = 8;

    /**
     * Selectable ranges → number of days back (null = since first activity).
     *
     * @var array<string, int|null>
     */
    private const array RANGES = [
        '7d' => 7,
        '30d' => 30,
        'all' => null,
    ];

    private static function normaliseRange(string $range): string
    {
        return array_key_exists($range, self::RANGES) ? $range : self::DEFAULT_RANGE;
    }

    /**
     * @param  Builder<Duration>  $durations  the scope the "all" range spans
     */
    private static function rangeStart(Builder $durations, string $range, CarbonImmutable $today, string $timezone): CarbonImmutable
    {
        $days = self::RANGES[$range];

        if ($days !== null) {
            return $today->subDays($days - 1);
        }

        $first = $durations->min('started_at');

        return $first !== null
            ? CarbonImmutable::parse($first, 'UTC')->setTimezone($timezone)->startOfDay()
            : $today;
    }

    /**
     * Total seconds keyed by day (Y-m-d) in the user's timezone. Days with no
     * activity are absent.
     *
     * @param  Collection<int, Duration>  $durations
     * @return array<string, int>
     */
    private static function secondsPerDay(Collection $durations, string $timezone): array
    {
        $perDay = [];

        foreach ($durations as $duration) {
            $day = $duration->started_at->setTimezone($timezone)->toDateString();
            $perDay[$day] = ($perDay[$day] ?? 0) + $duration->duration_seconds;
        }

        return $perDay;
    }

    /**
     * A continuous per-day series across the range, filling empty days with 0
     * so the activity chart has one bar per calendar day.
     *
     * @param  array<string, int>  $perDay
     * @return array<int, array{date: string, seconds: int}>
     */
    private static function activity(array $perDay, CarbonImmutable $from, CarbonImmutable $today): array
    {
        $activity = [];

        for ($day = $from; $day <= $today; $day = $day->addDay()) {
            $date = $day->toDateString();
            $activity[] = ['date' => $date, 'seconds' => $perDay[$date] ?? 0];
        }

        return $activity;
    }

    /**
     * @param  array<string, int>  $perDay
     * @return array{date: string, seconds: int}|null
     */
    private static function mostActiveDay(array $perDay): ?array
    {
        if ($perDay === []) {
            return null;
        }

        $date = array_first(array_keys($perDay, max($perDay)));

        return ['date' => $date, 'seconds' => $perDay[$date]];
    }

    /**
     * Top buckets for one duration column, summed and sorted descending. Null or
     * empty values collapse into $emptyLabel (e.g. AI-session events carry no
     * language) so every breakdown still sums to the total. An optional
     * $normaliseKey remaps each bucket key before summing (e.g. folding
     * non-languages into "Other").
     *
     * @param  Collection<int, Duration>  $durations
     * @param  (callable(string): string)|null  $normaliseKey
     * @return array<int, array{key: string, seconds: int}>
     */
    private static function breakdown(Collection $durations, string $column, string $emptyLabel, ?callable $normaliseKey = null): array
    {
        return self::topBuckets(self::durationTotals($durations, $column), $emptyLabel, $normaliseKey);
    }

    /**
     * Seconds per bucket for one duration column, keyed by its value ('' for
     * null or empty) so totals can merge with stored summary buckets.
     *
     * @param  Collection<int, Duration>  $durations
     * @return array<string, int>
     */
    private static function durationTotals(Collection $durations, string $column): array
    {
        $totals = [];

        foreach ($durations as $duration) {
            $value = $duration->{$column};
            $key = is_string($value) && $value !== '' ? $value : '';
            $totals[$key] = ($totals[$key] ?? 0) + $duration->duration_seconds;
        }

        return $totals;
    }

    /**
     * The heaviest buckets, sorted descending, with the '' bucket labelled. An
     * optional $normaliseKey remaps each key first — keys that collapse to the
     * same normalised value are summed together.
     *
     * @param  array<string, int>  $totals
     * @param  (callable(string): string)|null  $normaliseKey
     * @return array<int, array{key: string, seconds: int}>
     */
    private static function topBuckets(array $totals, string $emptyLabel, ?callable $normaliseKey = null): array
    {
        if ($normaliseKey !== null) {
            $normalised = [];

            foreach ($totals as $key => $seconds) {
                $mapped = $normaliseKey((string) $key);
                $normalised[$mapped] = ($normalised[$mapped] ?? 0) + $seconds;
            }

            $totals = $normalised;
        }

        arsort($totals);

        return collect($totals)
            ->take(self::BREAKDOWN_LIMIT)
            ->map(fn (int $seconds, string|int $key): array => [
                'key' => $key === '' ? $emptyLabel : (string) $key,
                'seconds' => $seconds,
            ])
            ->values()
            ->all();
    }
}
