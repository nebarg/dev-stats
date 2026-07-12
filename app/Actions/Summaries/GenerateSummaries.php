<?php

namespace App\Actions\Summaries;

use App\Models\DailyMetric;
use App\Models\Heartbeat;
use App\Models\SummaryItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Rolls a user's durations into per-day `summary_items` and their heartbeats
 * into per-day `daily_metrics`, resuming from `summaries_generated_until`.
 *
 * Days are calendar days in the user's timezone and only whole past days are
 * persisted — today is always computed live on read. Buckets are built in PHP
 * (not SQL) so day boundaries land in the user's timezone identically on
 * SQLite (tests) and MySQL (prod).
 */
class GenerateSummaries
{
    /**
     * @return array{days: int, items: int, metrics: int}
     */
    public static function forUser(User $user): array
    {
        $timezone = $user->timezone;
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $start = self::firstUnsummarisedDay($user, $timezone);
        $end = $today->subDay();

        if ($start === null || $start->greaterThan($end)) {
            return ['days' => 0, 'items' => 0, 'metrics' => 0];
        }

        $items = self::summaryItemRows($user, $start, $end, $timezone);
        $metrics = self::dailyMetricRows($user, $start, $end, $timezone);

        DB::transaction(static function () use ($user, $start, $end, $items, $metrics): void {
            $window = [$start->toDateString(), $end->toDateString()];

            SummaryItem::query()->where('user_id', $user->id)->whereBetween('day', $window)->delete();
            DailyMetric::query()->where('user_id', $user->id)->whereBetween('day', $window)->delete();

            foreach (array_chunk($items, 500) as $chunk) {
                SummaryItem::insert($chunk);
            }

            foreach (array_chunk($metrics, 500) as $chunk) {
                DailyMetric::insert($chunk);
            }

            $user->summaries_generated_until = $end->toDateString();
            $user->save();
        });

        return [
            'days' => (int) $start->diffInDays($end) + 1,
            'items' => count($items),
            'metrics' => count($metrics),
        ];
    }

    /**
     * The day after the last summarised one, or the day of the user's first
     * heartbeat when nothing has been summarised yet. Null without heartbeats.
     */
    private static function firstUnsummarisedDay(User $user, string $timezone): ?CarbonImmutable
    {
        if ($user->summaries_generated_until !== null) {
            return CarbonImmutable::parse($user->summaries_generated_until->toDateString(), $timezone)->addDay();
        }

        $first = $user->heartbeats()->min('recorded_at');

        return $first !== null
            ? CarbonImmutable::parse($first, 'UTC')->setTimezone($timezone)->startOfDay()
            : null;
    }

    /**
     * One row per (day, type, key) with the total seconds of that bucket.
     * Sessions never cross a day boundary, so bucketing by start time is
     * exact. Null and empty dimension values share the null-key bucket.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function summaryItemRows(User $user, CarbonImmutable $start, CarbonImmutable $end, string $timezone): array
    {
        $totals = [];

        $durations = $user->durations()
            ->where('started_at', '>=', $start->setTimezone('UTC'))
            ->where('started_at', '<', $end->addDay()->setTimezone('UTC'))
            ->lazy();

        foreach ($durations as $duration) {
            $day = $duration->started_at->setTimezone($timezone)->toDateString();

            foreach (SummaryItem::TYPES as $type) {
                $totals[$day][$type][$duration->{$type} ?? ''] ??= 0;
                $totals[$day][$type][$duration->{$type} ?? ''] += $duration->duration_seconds;
            }
        }

        $now = CarbonImmutable::now();
        $rows = [];

        foreach ($totals as $day => $types) {
            foreach ($types as $type => $keys) {
                foreach ($keys as $key => $seconds) {
                    $rows[] = [
                        'user_id' => $user->id,
                        'day' => $day,
                        'type' => $type,
                        'key' => $key === '' ? null : (string) $key,
                        'total_seconds' => $seconds,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * One row per (day, project, editor) summing the AI authorship columns of
     * heartbeats that carry any. A prompt event is a heartbeat with an
     * `ai_prompt_length`, so averages stay derivable from sum and count.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function dailyMetricRows(User $user, CarbonImmutable $start, CarbonImmutable $end, string $timezone): array
    {
        $buckets = [];

        $heartbeats = $user->heartbeats()
            ->where('recorded_at', '>=', $start->setTimezone('UTC'))
            ->where('recorded_at', '<', $end->addDay()->setTimezone('UTC'))
            ->where(static function (Builder $query): void {
                $query->whereNotNull('ai_line_changes')
                    ->orWhereNotNull('human_line_changes')
                    ->orWhereNotNull('ai_input_tokens')
                    ->orWhereNotNull('ai_output_tokens')
                    ->orWhereNotNull('ai_prompt_length');
            })
            ->select([
                'recorded_at', 'project', 'editor', 'ai_line_changes', 'human_line_changes',
                'ai_input_tokens', 'ai_output_tokens', 'ai_prompt_length',
            ])
            ->lazy();

        foreach ($heartbeats as $heartbeat) {
            $day = $heartbeat->recorded_at->setTimezone($timezone)->toDateString();
            $bucket = $buckets[$day][$heartbeat->project ?? ''][$heartbeat->editor ?? '']
                ?? ['ai_lines' => 0, 'human_lines' => 0, 'ai_input_tokens' => 0, 'ai_output_tokens' => 0, 'ai_prompts' => 0, 'ai_prompt_length' => 0];

            $bucket['ai_lines'] += $heartbeat->ai_line_changes ?? 0;
            $bucket['human_lines'] += $heartbeat->human_line_changes ?? 0;
            $bucket['ai_input_tokens'] += $heartbeat->ai_input_tokens ?? 0;
            $bucket['ai_output_tokens'] += $heartbeat->ai_output_tokens ?? 0;
            $bucket['ai_prompts'] += $heartbeat->ai_prompt_length !== null ? 1 : 0;
            $bucket['ai_prompt_length'] += $heartbeat->ai_prompt_length ?? 0;

            $buckets[$day][$heartbeat->project ?? ''][$heartbeat->editor ?? ''] = $bucket;
        }

        $now = CarbonImmutable::now();
        $rows = [];

        foreach ($buckets as $day => $projects) {
            foreach ($projects as $project => $editors) {
                foreach ($editors as $editor => $bucket) {
                    $rows[] = [
                        'user_id' => $user->id,
                        'day' => $day,
                        'project' => $project === '' ? null : (string) $project,
                        'editor' => $editor === '' ? null : (string) $editor,
                        ...$bucket,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        return $rows;
    }
}
