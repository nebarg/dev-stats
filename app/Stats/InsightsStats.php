<?php

namespace App\Stats;

use App\Models\Duration;
use App\Models\User;
use App\Stats\Support\Aggregation;
use App\Stats\Support\InsightsRange;
use App\Stats\Support\StoredLiveWindow;
use Carbon\CarbonImmutable;

/**
 * Builds the insights view-model over a selectable range (a rolling trailing
 * year or a calendar year): calendar heatmaps (coding time and AI share per
 * day), weekday averages with an AI-time portion, and authorship rankings.
 * Composes focused calculators; each view-model piece is its own class.
 *
 * Covered past days read stored summaries; the uncovered tail (always including
 * today) is computed live and merged.
 */
class InsightsStats
{
    public function __construct(
        private readonly SummaryReader $summaries,
        private readonly Aggregation $aggregation,
        private readonly InsightsRange $ranges,
        private readonly AiShareCalendar $aiCalendar,
        private readonly WeekdayAverages $weekdays,
        private readonly AuthorshipRankings $authorship,
        private readonly FileTimeRanking $fileTime,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, string $range = InsightsRange::ROLLING): array
    {
        $timezone = $user->timezone;
        $now = CarbonImmutable::now($timezone)->startOfDay();

        $ranges = $this->ranges->available($user, $now);
        $range = in_array($range, $ranges, true) ? $range : InsightsRange::ROLLING;

        // `$to` is the last day shown (Dec 31 for a past year, so the calendar
        // renders the whole year); `$dataEnd` caps that at today, since nothing
        // beyond today has data and averages must not divide by future days.
        [$from, $to] = $this->ranges->bounds($range, $now, $timezone);
        $dataEnd = $to->min($now);

        $coveredUntil = $this->summaries->coveredUntil($user, $now);
        $window = StoredLiveWindow::resolve($coveredUntil, $from, $dataEnd);

        $liveDurations = Duration::query()
            ->forUser($user)
            ->startedBetween($window->liveFrom(), $dataEnd)
            ->get(['started_at', 'duration_seconds', 'project', 'category']);

        $perDay = $this->aggregation->mergeTotals(
            $window->hasStored() ? $this->summaries->secondsPerDay($user, $from, $window->storedUntil) : [],
            $this->aggregation->secondsPerDay($liveDurations, $timezone),
        );

        $aiPerDay = $this->aggregation->mergeTotals(
            $window->hasStored() ? $this->summaries->categorySecondsPerDay($user, $from, $window->storedUntil, 'ai coding') : [],
            $this->aggregation->secondsPerDay(
                $liveDurations->filter(static fn (Duration $duration): bool => $duration->category === 'ai coding'),
                $timezone,
            ),
        );

        // Time rankings work for every year; the authorship rankings only have
        // data from 2026 on, when the CLI began sending line changes.
        $projectTime = $this->aggregation->mergeTotals(
            $window->hasStored() ? $this->summaries->bucketTotals($user, $from, $window->storedUntil, 'project') : [],
            $this->aggregation->bucketTotals($liveDurations, 'project'),
        );

        return [
            'range' => $range,
            'ranges' => $ranges,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'calendar' => $this->aggregation->activity($perDay, $from, $to),
            'ai_calendar' => $this->aiCalendar->forUser($user, $window, $to, $timezone),
            'weekdays' => $this->weekdays->calculate($perDay, $aiPerDay, $from, $dataEnd),
            'top_projects' => $this->aggregation->topBuckets($projectTime, 'No project'),
            'top_files' => $this->fileTime->forUser($user, $from, $dataEnd, $timezone),
            ...$this->authorship->forUser($user, $window),
        ];
    }
}
