<?php

namespace App\Stats\Support;

use App\Models\Duration;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The dashboard and project ranges: a fixed number of trailing days, or "all"
 * time since the first tracked activity in a given scope.
 */
class RangeResolver
{
    public const string DEFAULT_RANGE = '7d';

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
     * @return array<int, string>
     */
    public function options(): array
    {
        return array_keys(self::RANGES);
    }

    public function normalise(string $range): string
    {
        return array_key_exists($range, self::RANGES) ? $range : self::DEFAULT_RANGE;
    }

    /**
     * The first day (midnight in the user's timezone) the range covers. "all"
     * starts at the first activity in $scope, or today when the scope is empty.
     *
     * @param  Builder<Duration>  $scope
     */
    public function start(Builder $scope, string $range, CarbonImmutable $today, string $timezone): CarbonImmutable
    {
        $days = self::RANGES[$range];

        if ($days !== null) {
            return $today->subDays($days - 1);
        }

        $first = $scope->min('started_at');

        return $first !== null
            ? CarbonImmutable::parse($first, 'UTC')->setTimezone($timezone)->startOfDay()
            : $today;
    }
}
