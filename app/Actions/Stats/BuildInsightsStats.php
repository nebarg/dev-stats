<?php

namespace App\Actions\Stats;

use App\Actions\Stats\Concerns\AggregatesDurations;
use App\Models\Duration;
use App\Models\Heartbeat;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Builds the insights view-model over a fixed trailing year: calendar heatmaps
 * (coding time and AI share per day), weekday averages with an AI-time
 * portion, and authorship rankings (top AI-assisted / human-edited projects
 * and files by net line changes).
 */
class BuildInsightsStats
{
    use AggregatesDurations;

    private const int WINDOW_DAYS = 365;

    private const int TOP_LIMIT = 8;

    /**
     * @var array<int, string>
     */
    private const array WEEKDAY_LABELS = [1 => 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    /**
     * @return array<string, mixed>
     */
    public static function forUser(User $user): array
    {
        $timezone = $user->timezone;
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $from = $today->subDays(self::WINDOW_DAYS - 1);

        $durations = Duration::query()
            ->where('user_id', $user->id)
            ->where('started_at', '>=', $from->setTimezone('UTC'))
            ->where('started_at', '<', $today->addDay()->setTimezone('UTC'))
            ->get(['started_at', 'duration_seconds', 'category']);

        $projectTotals = self::lineTotals($user, $from, $today, 'project');
        $fileTotals = self::lineTotals($user, $from, $today, 'entity');

        return [
            'from' => $from->toDateString(),
            'to' => $today->toDateString(),
            'calendar' => self::activity(self::secondsPerDay($durations, $timezone), $from, $today),
            'ai_calendar' => self::aiCalendar($user, $from, $today, $timezone),
            'weekdays' => self::weekdayAverages($durations, $timezone, $from, $today),
            'top_ai_projects' => self::top($projectTotals, 'ai_lines'),
            'top_human_projects' => self::top($projectTotals, 'human_lines'),
            'top_ai_files' => self::top($fileTotals, 'ai_lines'),
            'top_human_files' => self::top($fileTotals, 'human_lines'),
        ];
    }

    /**
     * Net AI and human line changes per day, for the AI-share calendar. Days
     * without line data are present with zeros so the grid stays continuous.
     *
     * @return array<int, array{date: string, ai_lines: int, human_lines: int}>
     */
    private static function aiCalendar(User $user, CarbonImmutable $from, CarbonImmutable $today, string $timezone): array
    {
        $perDay = [];

        $heartbeats = self::lineHeartbeats($user, $from, $today)
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

        for ($day = $from; $day <= $today; $day = $day->addDay()) {
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
     * Average coding seconds per weekday — total time on that weekday divided
     * by how often it occurs in the window — with the `ai coding` share broken
     * out so the bars can stack an AI portion.
     *
     * @param  Collection<int, Duration>  $durations
     * @return array<int, array{label: string, average_seconds: int, ai_average_seconds: int}>
     */
    private static function weekdayAverages(Collection $durations, string $timezone, CarbonImmutable $from, CarbonImmutable $today): array
    {
        $totals = array_fill(1, 7, 0);
        $aiTotals = array_fill(1, 7, 0);
        $occurrences = array_fill(1, 7, 0);

        for ($day = $from; $day <= $today; $day = $day->addDay()) {
            $occurrences[$day->dayOfWeekIso]++;
        }

        foreach ($durations as $duration) {
            $weekday = $duration->started_at->setTimezone($timezone)->dayOfWeekIso;
            $totals[$weekday] += $duration->duration_seconds;

            if ($duration->category === 'ai coding') {
                $aiTotals[$weekday] += $duration->duration_seconds;
            }
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
     * Net line changes grouped by one heartbeat column. File rows keep the
     * full path and project alongside a basename display key.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function lineTotals(User $user, CarbonImmutable $from, CarbonImmutable $today, string $column): array
    {
        $isFileRanking = $column === 'entity';

        $rows = self::lineHeartbeats($user, $from, $today)
            ->when($isFileRanking, static fn (Builder $query) => $query->where('entity_type', 'file'))
            ->groupBy($isFileRanking ? ['entity', 'project'] : [$column])
            ->selectRaw(
                ($isFileRanking ? 'entity, project, ' : "{$column}, ")
                .'COALESCE(SUM(ai_line_changes), 0) AS ai_lines, '
                .'COALESCE(SUM(human_line_changes), 0) AS human_lines'
            )
            ->get();

        return $rows
            ->map(static function ($row) use ($column, $isFileRanking): array {
                $totals = [
                    'key' => $isFileRanking ? basename($row->entity) : ($row->{$column} ?? 'No project'),
                    'ai_lines' => (int) $row->ai_lines,
                    'human_lines' => (int) $row->human_lines,
                ];

                return $isFileRanking
                    ? [...$totals, 'path' => $row->entity, 'project' => $row->project]
                    : $totals;
            })
            ->all();
    }

    /**
     * @return Builder<Heartbeat>
     */
    private static function lineHeartbeats(User $user, CarbonImmutable $from, CarbonImmutable $today): Builder
    {
        return Heartbeat::query()
            ->where('user_id', $user->id)
            ->where('recorded_at', '>=', $from->setTimezone('UTC'))
            ->where('recorded_at', '<', $today->addDay()->setTimezone('UTC'))
            ->where(static function (Builder $query): void {
                $query->whereNotNull('ai_line_changes')->orWhereNotNull('human_line_changes');
            });
    }

    /**
     * The heaviest rows by one signed line column, positives only — a net
     * deletion isn't a "top" entry.
     *
     * @param  array<int, array<string, mixed>>  $totals
     * @return array<int, array<string, mixed>>
     */
    private static function top(array $totals, string $lineColumn): array
    {
        return collect($totals)
            ->filter(static fn (array $row): bool => $row[$lineColumn] > 0)
            ->sortByDesc($lineColumn)
            ->take(self::TOP_LIMIT)
            ->values()
            ->all();
    }
}
