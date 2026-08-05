<?php

namespace App\Stats;

use App\Models\Duration;
use App\Models\User;
use App\Stats\Support\Aggregation;
use App\Stats\Support\RangeResolver;
use App\Stats\Support\StoredLiveWindow;
use App\Support\CategoryLabel;
use App\Support\LanguageClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Builds the dashboard view-model for a range: headline time from stored daily
 * summaries plus a live tail from `durations` (always including today), and AI
 * line/token counts from raw `heartbeats`. Composes the focused calculators
 * rather than computing everything inline.
 */
class DashboardStats
{
    public function __construct(
        private readonly RangeResolver $ranges,
        private readonly SummaryReader $summaries,
        private readonly Aggregation $aggregation,
        private readonly FocusCalculator $focus,
        private readonly StreakCalculator $streak,
        private readonly EditingReport $editing,
        private readonly AiUsageReport $ai,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, string $range = RangeResolver::DEFAULT_RANGE): array
    {
        $range = $this->ranges->normalise($range);
        $timezone = $user->timezone;
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $from = $this->ranges->start(Duration::query()->forUser($user), $range, $today, $timezone);

        $coveredUntil = $this->summaries->coveredUntil($user, $today);
        $window = StoredLiveWindow::resolve($coveredUntil, $from, $today);

        // Focus needs every duration in range, so the full fetch stays; the
        // other aggregates read stored summaries for covered days and use only
        // the live tail beyond them.
        $durations = $this->durations($user, $from, $today);
        $liveDurations = $window->hasStored()
            ? $durations->filter(fn (Duration $duration): bool => $duration->started_at->greaterThanOrEqualTo($window->liveFrom()))->values()
            : $durations;

        $perDay = $this->aggregation->mergeTotals(
            $window->hasStored() ? $this->summaries->secondsPerDay($user, $from, $window->storedUntil) : [],
            $this->aggregation->secondsPerDay($liveDurations, $timezone),
        );

        $breakdown = fn (string $type, string $emptyLabel, ?callable $normaliseKey = null): array => $this->aggregation->topBuckets(
            $this->aggregation->mergeTotals(
                $window->hasStored() ? $this->summaries->bucketTotals($user, $from, $window->storedUntil, $type) : [],
                $this->aggregation->bucketTotals($liveDurations, $type),
            ),
            $emptyLabel,
            $normaliseKey,
        );

        $total = array_sum($perDay);
        $activeDays = count($perDay);

        return [
            'range' => $range,
            'ranges' => $this->ranges->options(),
            'from' => $from->toDateString(),
            'to' => $today->toDateString(),
            'total_seconds' => $total,
            'today_seconds' => $perDay[$today->toDateString()] ?? 0,
            'daily_average_seconds' => $activeDays > 0 ? intdiv($total, $activeDays) : 0,
            'active_days' => $activeDays,
            'most_active_day' => $this->aggregation->mostActiveDay($perDay),
            'activity' => $this->aggregation->activity($perDay, $from, $today),
            'focus' => $this->focus->calculate($durations),
            'streak' => $this->streak->calculate($user, $timezone, $today, $coveredUntil),
            'editing' => $this->editing->forUser($user, $from, $today),
            'ai' => $this->ai->forUser($user, $from, $today),
            'breakdowns' => [
                'projects' => $breakdown('project', 'No project'),
                'languages' => $breakdown('language', LanguageClassifier::classify(null), LanguageClassifier::classify(...)),
                'editors' => $breakdown('editor', 'Unknown editor'),
                'operating_systems' => $breakdown('operating_system', 'Unknown OS'),
                'categories' => $breakdown('category', 'Uncategorised', CategoryLabel::format(...)),
            ],
        ];
    }

    /**
     * @return Collection<int, Duration>
     */
    private function durations(User $user, CarbonImmutable $from, CarbonImmutable $today): Collection
    {
        return Duration::query()
            ->forUser($user)
            ->startedBetween($from, $today)
            ->orderBy('started_at')
            ->get([
                'started_at', 'duration_seconds', 'project', 'language',
                'editor', 'operating_system', 'category', 'timeout_seconds',
            ]);
    }
}
