<?php

namespace App\Stats;

use App\Models\Duration;
use App\Models\User;
use App\Stats\Support\Aggregation;
use App\Stats\Support\StoredLiveWindow;
use Carbon\CarbonImmutable;

/**
 * Streaks of consecutive days with at least a threshold of coding, computed
 * over a fixed trailing window regardless of the selected range. A quiet today
 * doesn't break the current streak — the day isn't over yet.
 */
class StreakCalculator
{
    private const int MINIMUM_SECONDS = 15 * 60;

    private const int WINDOW_DAYS = 400;

    public function __construct(
        private readonly SummaryReader $summaries,
        private readonly Aggregation $aggregation,
    ) {}

    /**
     * @return array{current_days: int, longest_days: int}
     */
    public function calculate(User $user, string $timezone, CarbonImmutable $today, ?CarbonImmutable $coveredUntil): array
    {
        $windowStart = $today->subDays(self::WINDOW_DAYS);
        $window = StoredLiveWindow::resolve($coveredUntil, $windowStart, $today);

        $durations = Duration::query()
            ->forUser($user)
            ->startedBetween($window->liveFrom())
            ->get(['started_at', 'duration_seconds']);

        $perDay = $this->aggregation->mergeTotals(
            $window->hasStored() ? $this->summaries->secondsPerDay($user, $windowStart, $window->storedUntil) : [],
            $this->aggregation->secondsPerDay($durations, $timezone),
        );

        $activeDays = array_keys(array_filter(
            $perDay,
            static fn (int $seconds): bool => $seconds >= self::MINIMUM_SECONDS,
        ));
        sort($activeDays);
        $isActive = static fn (CarbonImmutable $day): bool => in_array($day->toDateString(), $activeDays, true);

        $currentDays = 0;
        $day = $isActive($today) ? $today : $today->subDay();

        while ($isActive($day)) {
            $currentDays++;
            $day = $day->subDay();
        }

        $longestDays = 0;
        $run = 0;
        $previous = null;

        foreach ($activeDays as $date) {
            $run = $previous !== null && CarbonImmutable::parse($previous)->addDay()->toDateString() === $date
                ? $run + 1
                : 1;
            $longestDays = max($longestDays, $run);
            $previous = $date;
        }

        return ['current_days' => $currentDays, 'longest_days' => $longestDays];
    }
}
