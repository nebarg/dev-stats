<?php

namespace App\Actions\Summaries;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds `daily_metrics` rows: one per (day, project, editor) summing the AI
 * authorship columns of heartbeats that carry any. A prompt event is a
 * heartbeat with an `ai_prompt_length`, so averages stay derivable from the
 * stored sum and count.
 */
class DailyMetricRows
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function for(User $user, CarbonImmutable $start, CarbonImmutable $end, string $timezone): array
    {
        $buckets = [];

        $heartbeats = $user->heartbeats()
            ->recordedBetween($start, $end)
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
