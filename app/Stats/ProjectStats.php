<?php

namespace App\Stats;

use App\Models\Duration;
use App\Models\Heartbeat;
use App\Models\User;
use App\Stats\Support\Aggregation;
use App\Stats\Support\RangeResolver;
use App\Support\CategoryLabel;
use App\Support\LanguageClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the project-detail view-model live for a range: time stats from the
 * project's `durations`, per-file time recomputed from raw `heartbeats` —
 * durations squash a session to its most prominent entity, so file-level
 * accuracy needs the raw stream.
 */
class ProjectStats
{
    private const int FILE_LIMIT = 15;

    public function __construct(
        private readonly RangeResolver $ranges,
        private readonly Aggregation $aggregation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, string $project, string $range = RangeResolver::DEFAULT_RANGE): array
    {
        $range = $this->ranges->normalise($range);
        $timezone = $user->timezone;
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $from = $this->ranges->start($this->projectDurations($user, $project), $range, $today, $timezone);

        $durations = $this->projectDurations($user, $project)
            ->startedBetween($from, $today)
            ->orderBy('started_at')
            ->get(['started_at', 'duration_seconds', 'language', 'editor', 'branch', 'category']);

        $perDay = $this->aggregation->secondsPerDay($durations, $timezone);
        $total = array_sum($perDay);
        $activeDays = count($perDay);
        [$files, $fileCount] = $this->files($user, $project, $from, $today);

        $breakdown = fn (string $column, string $emptyLabel, ?callable $normaliseKey = null): array => $this->aggregation->topBuckets(
            $this->aggregation->bucketTotals($durations, $column),
            $emptyLabel,
            $normaliseKey,
        );

        return [
            'project' => $project,
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
            'files' => $files,
            'file_count' => $fileCount,
            'breakdowns' => [
                'languages' => $breakdown('language', LanguageClassifier::classify(null), LanguageClassifier::classify(...)),
                'branches' => $breakdown('branch', 'No branch'),
                'editors' => $breakdown('editor', 'Unknown editor'),
                'categories' => $breakdown('category', 'Uncategorised', CategoryLabel::format(...)),
            ],
        ];
    }

    /**
     * @return Builder<Duration>
     */
    private function projectDurations(User $user, string $project): Builder
    {
        return Duration::query()->forUser($user)->where('project', $project);
    }

    /**
     * Per-file time recomputed live with the sessionization rule: the gap to the
     * next heartbeat, when under the timeout and within the same day, is
     * credited to the file of the heartbeat that opened it. The whole heartbeat
     * stream takes part — a spell in another project between two same-file
     * heartbeats closes the gap there, not on the file — but only this project's
     * file entities accumulate.
     *
     * @return array{0: array<int, array{key: string, path: string, seconds: int, ai_lines: int, human_lines: int}>, 1: int}
     */
    private function files(User $user, string $project, CarbonImmutable $from, CarbonImmutable $today): array
    {
        $timeoutMs = (int) config('stats.heartbeat_timeout_sec') * 1000;
        $timezone = $user->timezone;

        $files = [];
        $previousEntity = null;
        $previousTimeMs = 0;
        $previousDay = null;

        $heartbeats = Heartbeat::query()
            ->forUser($user)
            ->recordedBetween($from, $today)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->lazy();

        foreach ($heartbeats as $heartbeat) {
            $timeMs = (int) $heartbeat->recorded_at->getPreciseTimestamp(3);
            $day = $heartbeat->recorded_at->setTimezone($timezone)->toDateString();

            if ($previousEntity !== null && $previousDay === $day) {
                $gapMs = $timeMs - $previousTimeMs;

                if ($gapMs > 0 && $gapMs < $timeoutMs) {
                    $files[$previousEntity]['milliseconds'] += $gapMs;
                }
            }

            $isProjectFile = $heartbeat->project === $project && $heartbeat->entity_type === 'file';

            if ($isProjectFile) {
                $files[$heartbeat->entity] ??= ['milliseconds' => 0, 'ai_lines' => 0, 'human_lines' => 0];
                $files[$heartbeat->entity]['ai_lines'] += $heartbeat->ai_line_changes ?? 0;
                $files[$heartbeat->entity]['human_lines'] += $heartbeat->human_line_changes ?? 0;
            }

            $previousEntity = $isProjectFile ? $heartbeat->entity : null;
            $previousTimeMs = $timeMs;
            $previousDay = $day;
        }

        $prefixLength = strlen($this->commonDirectoryPrefix(array_keys($files)));

        $top = collect($files)
            ->map(static fn (array $file, string $path): array => [
                'key' => substr($path, $prefixLength),
                'path' => $path,
                'seconds' => (int) round($file['milliseconds'] / 1000),
                'ai_lines' => $file['ai_lines'],
                'human_lines' => $file['human_lines'],
            ])
            ->sortByDesc('seconds')
            ->take(self::FILE_LIMIT)
            ->values()
            ->all();

        return [$top, count($files)];
    }

    /**
     * The directory prefix (trailing slash included) shared by every path, so
     * file names display relative to the project root. A lone file reduces to
     * its basename.
     *
     * @param  array<int, string>  $paths
     */
    private function commonDirectoryPrefix(array $paths): string
    {
        if ($paths === []) {
            return '';
        }

        $directories = array_map(
            static fn (string $path): array => array_slice(explode('/', $path), 0, -1),
            $paths,
        );

        $prefix = array_first($directories);

        foreach ($directories as $segments) {
            $shared = [];

            foreach ($segments as $index => $segment) {
                if (($prefix[$index] ?? null) !== $segment) {
                    break;
                }

                $shared[] = $segment;
            }

            $prefix = $shared;
        }

        return $prefix === [] ? '' : implode('/', $prefix).'/';
    }
}
