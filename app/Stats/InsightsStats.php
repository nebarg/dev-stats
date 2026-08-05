<?php

namespace App\Stats;

use App\Models\Duration;
use App\Models\Heartbeat;
use App\Models\User;
use App\Stats\Support\Aggregation;
use App\Stats\Support\StoredLiveWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the insights view-model over a selectable range (a rolling trailing
 * year or a calendar year): calendar heatmaps (coding time and AI share per
 * day), weekday averages with an AI-time portion, and authorship rankings.
 *
 * Covered past days read stored summaries; the uncovered tail (always including
 * today) is computed live and merged. File rankings stay fully live — per-file
 * detail is deliberately not pre-aggregated.
 */
class InsightsStats
{
    private const int WINDOW_DAYS = 365;

    private const int TOP_LIMIT = 8;

    /** The rolling trailing-year range; the alternatives are calendar years. */
    public const string ROLLING_RANGE = '12m';

    /**
     * @var array<int, string>
     */
    private const array WEEKDAY_LABELS = [1 => 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    public function __construct(
        private readonly SummaryReader $summaries,
        private readonly Aggregation $aggregation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, string $range = self::ROLLING_RANGE): array
    {
        $timezone = $user->timezone;
        $now = CarbonImmutable::now($timezone)->startOfDay();

        $ranges = $this->availableRanges($user, $now);
        $range = in_array($range, $ranges, true) ? $range : self::ROLLING_RANGE;

        // `$to` is the last day shown (Dec 31 for a past year, so the calendar
        // renders the whole year); `$dataEnd` caps that at today, since nothing
        // beyond today has data and averages must not divide by future days.
        [$from, $to] = $this->resolveBounds($range, $now, $timezone);
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

        $projectTotals = $this->projectLineTotals($user, $window);
        $fileTotals = $this->fileLineTotals($user, $from, $dataEnd);

        // Time rankings work for every year; the line-authorship rankings below
        // only have data from 2026 on, when the CLI began sending it.
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
            'ai_calendar' => $this->aiCalendar($user, $window, $to, $timezone),
            'weekdays' => $this->weekdayAverages($perDay, $aiPerDay, $from, $dataEnd),
            'top_projects' => $this->aggregation->topBuckets($projectTime, 'No project'),
            'top_files' => $this->fileTimeTotals($user, $from, $dataEnd, $timezone),
            'top_ai_projects' => $this->top($projectTotals, 'ai_lines'),
            'top_human_projects' => $this->top($projectTotals, 'human_lines'),
            'top_ai_files' => $this->top($fileTotals, 'ai_lines'),
            'top_human_files' => $this->top($fileTotals, 'human_lines'),
        ];
    }

    /**
     * Time per file over the period, live from heartbeats: the gap to each
     * heartbeat, when under the timeout and within the same day, is credited to
     * the file of the heartbeat that opened it (non-file heartbeats break the
     * chain). Ranked by time — the one authorship-independent file signal, so it
     * works for pre-2026 years too.
     *
     * @return array<int, array{key: string, seconds: int}>
     */
    private function fileTimeTotals(User $user, CarbonImmutable $from, CarbonImmutable $dataEnd, string $timezone): array
    {
        $timeoutMs = (int) config('stats.heartbeat_timeout_sec') * 1000;

        $files = [];
        $previousEntity = null;
        $previousTimeMs = 0;
        $previousDay = null;

        $heartbeats = Heartbeat::query()
            ->forUser($user)
            ->recordedBetween($from, $dataEnd)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->lazy();

        foreach ($heartbeats as $heartbeat) {
            $timeMs = (int) $heartbeat->recorded_at->getPreciseTimestamp(3);
            $day = $heartbeat->recorded_at->setTimezone($timezone)->toDateString();

            if ($previousEntity !== null && $previousDay === $day) {
                $gapMs = $timeMs - $previousTimeMs;

                if ($gapMs > 0 && $gapMs < $timeoutMs) {
                    $files[$previousEntity] = ($files[$previousEntity] ?? 0) + $gapMs;
                }
            }

            $previousEntity = $heartbeat->entity_type === 'file' ? $heartbeat->entity : null;
            $previousTimeMs = $timeMs;
            $previousDay = $day;
        }

        return collect($files)
            ->map(static fn (int $milliseconds, string $entity): array => [
                'key' => basename(str_replace('\\', '/', $entity)),
                'seconds' => (int) round($milliseconds / 1000),
            ])
            ->sortByDesc('seconds')
            ->take(self::TOP_LIMIT)
            ->values()
            ->all();
    }

    /**
     * The selectable ranges: the rolling trailing year, then each calendar year
     * from the current one back to the user's first tracked year (descending).
     *
     * @return array<int, string>
     */
    private function availableRanges(User $user, CarbonImmutable $now): array
    {
        $first = Duration::query()->forUser($user)->min('started_at');

        $firstYear = $first !== null
            ? CarbonImmutable::parse($first, 'UTC')->setTimezone($user->timezone)->year
            : $now->year;

        $years = array_map('strval', range($now->year, $firstYear));

        return [self::ROLLING_RANGE, ...$years];
    }

    /**
     * Resolve a range to its first and last day (both midnight in the user's
     * timezone). A four-digit year spans that whole calendar year.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveBounds(string $range, CarbonImmutable $now, string $timezone): array
    {
        if ($range === self::ROLLING_RANGE) {
            return [$now->subDays(self::WINDOW_DAYS - 1), $now];
        }

        $from = CarbonImmutable::create((int) $range, 1, 1, 0, 0, 0, $timezone);

        return [$from, $from->addYear()->subDay()];
    }

    /**
     * Net AI and human line changes per day for the AI-share calendar: stored
     * daily metrics for covered days, heartbeats for the live tail. Days without
     * line data are present with zeros so the grid stays continuous.
     *
     * @return array<int, array{date: string, ai_lines: int, human_lines: int}>
     */
    private function aiCalendar(User $user, StoredLiveWindow $window, CarbonImmutable $to, string $timezone): array
    {
        $perDay = $window->hasStored() ? $this->summaries->linesPerDay($user, $window->from, $window->storedUntil) : [];

        $heartbeats = $this->lineHeartbeats($user, $window->liveFrom(), $window->through)
            ->select(['recorded_at', 'ai_line_changes', 'human_line_changes'])
            ->lazy();

        foreach ($heartbeats as $heartbeat) {
            $day = $heartbeat->recorded_at->setTimezone($timezone)->toDateString();
            $totals = $perDay[$day] ?? ['ai_lines' => 0, 'human_lines' => 0];
            $totals['ai_lines'] += $heartbeat->ai_line_changes ?? 0;
            $totals['human_lines'] += $heartbeat->human_line_changes ?? 0;
            $perDay[$day] = $totals;
        }

        $calendar = [];

        for ($day = $window->from; $day <= $to; $day = $day->addDay()) {
            $date = $day->toDateString();
            $calendar[] = [
                'date' => $date,
                'ai_lines' => $perDay[$date]['ai_lines'] ?? 0,
                'human_lines' => $perDay[$date]['human_lines'] ?? 0,
            ];
        }

        return $calendar;
    }

    /**
     * Average coding seconds per weekday — total time on that weekday divided by
     * how often it occurs in the window — with the `ai coding` share broken out
     * so the bars can stack an AI portion.
     *
     * @param  array<string, int>  $perDay
     * @param  array<string, int>  $aiPerDay
     * @return array<int, array{label: string, average_seconds: int, ai_average_seconds: int}>
     */
    private function weekdayAverages(array $perDay, array $aiPerDay, CarbonImmutable $from, CarbonImmutable $dataEnd): array
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

        return collect(self::WEEKDAY_LABELS)
            ->map(static fn (string $label, int $weekday): array => [
                'label' => $label,
                'average_seconds' => intdiv($totals[$weekday], max(1, $occurrences[$weekday])),
                'ai_average_seconds' => intdiv($aiTotals[$weekday], max(1, $occurrences[$weekday])),
            ])
            ->values()
            ->all();
    }

    /**
     * Net line changes per project: stored daily metrics for covered days plus
     * the live heartbeat tail, merged by project.
     *
     * @return array<int, array{key: string, ai_lines: int, human_lines: int}>
     */
    private function projectLineTotals(User $user, StoredLiveWindow $window): array
    {
        $totals = $window->hasStored() ? $this->summaries->projectLineTotals($user, $window->from, $window->storedUntil) : [];

        $rows = $this->lineHeartbeats($user, $window->liveFrom(), $window->through)
            ->groupBy('project')
            ->toBase()
            ->selectRaw(
                'project, '
                .'COALESCE(SUM(ai_line_changes), 0) AS ai_lines, '
                .'COALESCE(SUM(human_line_changes), 0) AS human_lines'
            )
            ->get();

        foreach ($rows as $row) {
            $bucket = $totals[$row->project ?? ''] ?? ['ai_lines' => 0, 'human_lines' => 0];
            $bucket['ai_lines'] += (int) $row->ai_lines;
            $bucket['human_lines'] += (int) $row->human_lines;
            $totals[$row->project ?? ''] = $bucket;
        }

        return collect($totals)
            ->map(static fn (array $bucket, string|int $project): array => [
                'key' => $project === '' ? 'No project' : (string) $project,
                'ai_lines' => $bucket['ai_lines'],
                'human_lines' => $bucket['human_lines'],
            ])
            ->values()
            ->all();
    }

    /**
     * Net line changes per file over the whole window, live from heartbeats.
     * Rows keep the full path and project alongside a basename display key.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fileLineTotals(User $user, CarbonImmutable $from, CarbonImmutable $dataEnd): array
    {
        $rows = $this->lineHeartbeats($user, $from, $dataEnd)
            ->where('entity_type', 'file')
            ->groupBy('entity', 'project')
            ->toBase()
            ->selectRaw(
                'entity, project, '
                .'COALESCE(SUM(ai_line_changes), 0) AS ai_lines, '
                .'COALESCE(SUM(human_line_changes), 0) AS human_lines'
            )
            ->get();

        return $rows
            ->map(static fn (object $row): array => [
                'key' => basename(str_replace('\\', '/', $row->entity)),
                'ai_lines' => (int) $row->ai_lines,
                'human_lines' => (int) $row->human_lines,
                'path' => $row->entity,
                'project' => $row->project,
            ])
            ->all();
    }

    /**
     * @return Builder<Heartbeat>
     */
    private function lineHeartbeats(User $user, CarbonImmutable $from, CarbonImmutable $end): Builder
    {
        return Heartbeat::query()
            ->forUser($user)
            ->recordedBetween($from, $end)
            ->withLineChanges();
    }

    /**
     * The heaviest rows by one signed line column, positives only — a net
     * deletion isn't a "top" entry.
     *
     * @param  array<int, array<string, mixed>>  $totals
     * @return array<int, array<string, mixed>>
     */
    private function top(array $totals, string $lineColumn): array
    {
        return collect($totals)
            ->filter(static fn (array $row): bool => $row[$lineColumn] > 0)
            ->sortByDesc($lineColumn)
            ->take(self::TOP_LIMIT)
            ->values()
            ->all();
    }
}
