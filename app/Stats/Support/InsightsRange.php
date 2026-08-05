<?php

namespace App\Stats\Support;

use App\Models\Duration;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The insights ranges: a rolling trailing year, or a specific calendar year
 * back to the user's first tracked year.
 */
class InsightsRange
{
    public const string ROLLING = '12m';

    private const int WINDOW_DAYS = 365;

    /**
     * The selectable ranges: the rolling trailing year, then each calendar year
     * from the current one back to the user's first tracked year (descending).
     *
     * @return array<int, string>
     */
    public function available(User $user, CarbonImmutable $now): array
    {
        $first = Duration::query()->forUser($user)->min('started_at');

        $firstYear = $first !== null
            ? CarbonImmutable::parse($first, 'UTC')->setTimezone($user->timezone)->year
            : $now->year;

        $years = array_map('strval', range($now->year, $firstYear));

        return [self::ROLLING, ...$years];
    }

    /**
     * Resolve a range to its first and last day (both midnight in the user's
     * timezone). A four-digit year spans that whole calendar year.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function bounds(string $range, CarbonImmutable $now, string $timezone): array
    {
        if ($range === self::ROLLING) {
            return [$now->subDays(self::WINDOW_DAYS - 1), $now];
        }

        $from = CarbonImmutable::create((int) $range, 1, 1, 0, 0, 0, $timezone);

        return [$from, $from->addYear()->subDay()];
    }
}
