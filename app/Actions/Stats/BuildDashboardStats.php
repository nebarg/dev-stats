<?php

namespace App\Actions\Stats;

use App\Models\Duration;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Builds the dashboard view-model live from a user's `durations` for a given
 * range. Aggregation is done in PHP (not SQL) so day buckets land in the user's
 * timezone and the logic stays identical on SQLite (tests) and MySQL (prod).
 */
class BuildDashboardStats
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

    /**
     * @return array<string, mixed>
     */
    public static function forUser(User $user, string $range = self::DEFAULT_RANGE): array
    {
        $range = array_key_exists($range, self::RANGES) ? $range : self::DEFAULT_RANGE;
        $timezone = $user->timezone;
        $today = Date::now($timezone)->startOfDay();
        $from = self::rangeStart($user, $range, $today);

        $durations = self::durations($user, $from, $today);
        $perDay = self::secondsPerDay($durations, $timezone);

        $total = array_sum($perDay);
        $activeDays = count($perDay);
        $mostActive = self::mostActiveDay($perDay);

        return [
            'range' => $range,
            'ranges' => array_keys(self::RANGES),
            'from' => $from->toDateString(),
            'to' => $today->toDateString(),
            'total_seconds' => $total,
            'today_seconds' => $perDay[$today->toDateString()] ?? 0,
            'daily_average_seconds' => $activeDays > 0 ? intdiv($total, $activeDays) : 0,
            'active_days' => $activeDays,
            'most_active_day' => $mostActive,
            'activity' => self::activity($perDay, $from, $today),
            'breakdowns' => [
                'projects' => self::breakdown($durations, 'project', 'No project'),
                'languages' => self::breakdown($durations, 'language', 'AI Session'),
                'editors' => self::breakdown($durations, 'editor', 'Unknown editor'),
                'operating_systems' => self::breakdown($durations, 'operating_system', 'Unknown OS'),
            ],
        ];
    }

    private static function rangeStart(User $user, string $range, CarbonImmutable $today): CarbonImmutable
    {
        $days = self::RANGES[$range];

        if ($days !== null) {
            return $today->subDays($days - 1);
        }

        $first = Duration::query()->where('user_id', $user->id)->min('started_at');

        return $first !== null
            ? Date::parse($first, 'UTC')->setTimezone($user->timezone)->startOfDay()
            : $today;
    }

    /**
     * @return Collection<int, Duration>
     */
    private static function durations(User $user, CarbonImmutable $from, CarbonImmutable $today): Collection
    {
        return Duration::query()
            ->where('user_id', $user->id)
            ->where('started_at', '>=', $from->setTimezone('UTC'))
            ->where('started_at', '<', $today->addDay()->setTimezone('UTC'))
            ->get(['started_at', 'duration_seconds', 'project', 'language', 'editor', 'operating_system']);
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

        $date = array_keys($perDay, max($perDay))[0];

        return ['date' => $date, 'seconds' => $perDay[$date]];
    }

    /**
     * Top buckets for one duration column, summed and sorted descending. Null or
     * empty values collapse into $emptyLabel (e.g. AI-session events carry no
     * language) so every breakdown still sums to the total.
     *
     * @param  Collection<int, Duration>  $durations
     * @return array<int, array{key: string, seconds: int}>
     */
    private static function breakdown(Collection $durations, string $column, string $emptyLabel): array
    {
        $totals = [];

        foreach ($durations as $duration) {
            $value = $duration->{$column};
            $key = is_string($value) && $value !== '' ? $value : $emptyLabel;
            $totals[$key] = ($totals[$key] ?? 0) + $duration->duration_seconds;
        }

        arsort($totals);

        return collect($totals)
            ->take(self::BREAKDOWN_LIMIT)
            ->map(fn (int $seconds, string $key): array => ['key' => $key, 'seconds' => $seconds])
            ->values()
            ->all();
    }
}
