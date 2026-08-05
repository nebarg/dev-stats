<?php

namespace App\Stats;

use App\Models\Heartbeat;
use App\Models\User;
use App\Stats\Support\StoredLiveWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The top AI-assisted and human-edited projects and files by net line changes.
 * Project totals merge stored daily metrics with the live heartbeat tail; file
 * totals stay fully live (per-file detail is not pre-aggregated). Line data
 * only exists from 2026 on, when the CLI began sending it.
 */
class AuthorshipRankings
{
    private const int TOP_LIMIT = 8;

    public function __construct(private readonly SummaryReader $summaries) {}

    /**
     * @return array{top_ai_projects: array<int, array<string, mixed>>, top_human_projects: array<int, array<string, mixed>>, top_ai_files: array<int, array<string, mixed>>, top_human_files: array<int, array<string, mixed>>}
     */
    public function forUser(User $user, StoredLiveWindow $window): array
    {
        $projects = $this->projectTotals($user, $window);
        $files = $this->fileTotals($user, $window->from, $window->through);

        return [
            'top_ai_projects' => $this->top($projects, 'ai_lines'),
            'top_human_projects' => $this->top($projects, 'human_lines'),
            'top_ai_files' => $this->top($files, 'ai_lines'),
            'top_human_files' => $this->top($files, 'human_lines'),
        ];
    }

    /**
     * Net line changes per project: stored daily metrics for covered days plus
     * the live heartbeat tail, merged by project.
     *
     * @return array<int, array{key: string, ai_lines: int, human_lines: int}>
     */
    private function projectTotals(User $user, StoredLiveWindow $window): array
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
    private function fileTotals(User $user, CarbonImmutable $from, CarbonImmutable $through): array
    {
        $rows = $this->lineHeartbeats($user, $from, $through)
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

    /**
     * @return Builder<Heartbeat>
     */
    private function lineHeartbeats(User $user, CarbonImmutable $from, CarbonImmutable $through): Builder
    {
        return Heartbeat::query()
            ->forUser($user)
            ->recordedBetween($from, $through)
            ->withLineChanges();
    }
}
