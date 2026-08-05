<?php

namespace App\Stats\Support;

use App\Models\Duration;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Stateless day-series and bucket aggregation over durations. Day bucketing is
 * done in PHP (not SQL) so boundaries land in the user's timezone and the logic
 * is identical on SQLite (tests) and MySQL (prod).
 */
class Aggregation
{
    private const int BREAKDOWN_LIMIT = 8;

    /**
     * Total seconds keyed by day (Y-m-d) in the given timezone; days with no
     * activity are absent.
     *
     * @param  Collection<int, Duration>  $durations
     * @return array<string, int>
     */
    public function secondsPerDay(Collection $durations, string $timezone): array
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
     * so charts get one point per calendar day.
     *
     * @param  array<string, int>  $perDay
     * @return array<int, array{date: string, seconds: int}>
     */
    public function activity(array $perDay, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $activity = [];

        for ($day = $from; $day <= $to; $day = $day->addDay()) {
            $date = $day->toDateString();
            $activity[] = ['date' => $date, 'seconds' => $perDay[$date] ?? 0];
        }

        return $activity;
    }

    /**
     * @param  array<string, int>  $perDay
     * @return array{date: string, seconds: int}|null
     */
    public function mostActiveDay(array $perDay): ?array
    {
        if ($perDay === []) {
            return null;
        }

        $date = array_first(array_keys($perDay, max($perDay)));

        return ['date' => $date, 'seconds' => $perDay[$date]];
    }

    /**
     * Seconds per bucket for one duration column, keyed by its value ('' for
     * null or empty) so totals can merge with stored summary buckets.
     *
     * @param  Collection<int, Duration>  $durations
     * @return array<string, int>
     */
    public function bucketTotals(Collection $durations, string $column): array
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
     * optional $normaliseKey remaps each key first; keys that collapse to the
     * same normalised value are summed together (e.g. folding non-languages
     * into "Other").
     *
     * @param  array<string, int>  $totals
     * @param  (callable(string): string)|null  $normaliseKey
     * @return array<int, array{key: string, seconds: int}>
     */
    public function topBuckets(array $totals, string $emptyLabel, ?callable $normaliseKey = null): array
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

    /**
     * Sum keyed integer maps (e.g. stored and live per-day seconds).
     *
     * @param  array<string, int>  ...$maps
     * @return array<string, int>
     */
    public function mergeTotals(array ...$maps): array
    {
        $merged = [];

        foreach ($maps as $map) {
            foreach ($map as $key => $value) {
                $merged[$key] = ($merged[$key] ?? 0) + $value;
            }
        }

        return $merged;
    }
}
