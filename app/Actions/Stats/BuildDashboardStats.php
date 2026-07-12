<?php

namespace App\Actions\Stats;

use App\Actions\Stats\Concerns\AggregatesDurations;
use App\Actions\Stats\Concerns\ReadsSummaries;
use App\Models\Duration;
use App\Models\Heartbeat;
use App\Models\User;
use App\Support\AiPricing;
use App\Support\UserAgentParser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Builds the dashboard view-model for a given range: time stats from stored
 * daily summaries plus a live tail computed from `durations` (always
 * including today), AI line/token counts from raw `heartbeats`.
 */
class BuildDashboardStats
{
    use AggregatesDurations;
    use ReadsSummaries;

    /**
     * A focus block must reach this length to count as deep work.
     */
    private const int DEEP_WORK_MINIMUM_SECONDS = 25 * 60;

    /**
     * A day needs at least this much coding time to count towards a streak.
     */
    private const int STREAK_MINIMUM_SECONDS = 15 * 60;

    private const int STREAK_WINDOW_DAYS = 400;

    /**
     * @return array<string, mixed>
     */
    public static function forUser(User $user, string $range = self::DEFAULT_RANGE): array
    {
        $range = self::normaliseRange($range);
        $timezone = $user->timezone;
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $from = self::rangeStart(Duration::query()->where('user_id', $user->id), $range, $today, $timezone);

        $coveredUntil = self::summariesCoveredUntil($user, $today);
        $hasStored = $coveredUntil !== null && $coveredUntil->greaterThanOrEqualTo($from);
        $liveFrom = $hasStored ? $coveredUntil->addDay() : $from;

        // Focus needs every duration in range, so the full fetch stays; the
        // other aggregates read stored summaries for covered days and use
        // only the fetched durations beyond them.
        $durations = self::durations($user, $from, $today);
        $liveDurations = $hasStored
            ? $durations->filter(fn (Duration $duration): bool => $duration->started_at->greaterThanOrEqualTo($liveFrom))->values()
            : $durations;

        $perDay = self::mergeTotals(
            $hasStored ? self::storedSecondsPerDay($user, $from, $coveredUntil) : [],
            self::secondsPerDay($liveDurations, $timezone),
        );

        $breakdown = fn (string $type, string $emptyLabel): array => self::topBuckets(
            self::mergeTotals(
                $hasStored ? self::storedBucketTotals($user, $from, $coveredUntil, $type) : [],
                self::durationTotals($liveDurations, $type),
            ),
            $emptyLabel,
        );

        $total = array_sum($perDay);
        $activeDays = count($perDay);
        $mostActive = self::mostActiveDay($perDay);
        $agents = self::agents($user, $from, $today);

        return [
            'range' => $range,
            'ranges' => array_keys(self::RANGES),
            'from' => $from->toDateString(),
            'to' => $today->toDateString(),
            'total_seconds' => $total,
            'today_seconds' => $perDay[$today->toDateString()] ?? 0,
            'daily_average_seconds' => $activeDays > 0 ? intdiv($total, $activeDays) : 0,
            'active_days' => $activeDays,
            'most_active_day' => $mostActive,
            'activity' => self::activity($perDay, $from, $today),
            'focus' => self::focus($durations),
            'streak' => self::streak($user, $timezone, $today, $coveredUntil),
            'editing' => self::editing($user, $from, $today),
            'ai' => [
                ...self::aiTotals($user, $from, $today),
                'estimated_cost_cents' => self::estimatedCost($agents),
                'agents' => array_slice($agents, 0, self::BREAKDOWN_LIMIT),
            ],
            'breakdowns' => [
                'projects' => $breakdown('project', 'No project'),
                'languages' => $breakdown('language', 'AI Session'),
                'editors' => $breakdown('editor', 'Unknown editor'),
                'operating_systems' => $breakdown('operating_system', 'Unknown OS'),
                'categories' => $breakdown('category', 'Uncategorised'),
            ],
        ];
    }

    /**
     * @return Collection<int, Duration>
     */
    private static function durations(User $user, CarbonImmutable $from, CarbonImmutable $today): Collection
    {
        return Duration::query()
            ->where('user_id', $user->id)
            ->where('started_at', '>=', $from->setTimezone('UTC'))
            ->where('started_at', '<', $today->addDay()->setTimezone('UTC'))
            ->orderBy('started_at')
            ->get([
                'started_at',
                'duration_seconds',
                'project',
                'language',
                'editor',
                'operating_system',
                'category',
                'timeout_seconds',
            ]);
    }

    /**
     * Focus metrics from time-ordered durations merged into "blocks": a
     * duration continues the current block when the gap to the block's end
     * stays within the timeout, regardless of which grouping key changed.
     * Context switches count project changes mid-block, not across breaks.
     *
     * @param  Collection<int, Duration>  $durations
     * @return array{longest_block_seconds: int, deep_work_seconds: int, deep_work_blocks: int, context_switches: int}
     */
    private static function focus(Collection $durations): array
    {
        $longestBlock = 0;
        $deepWorkSeconds = 0;
        $deepWorkBlocks = 0;
        $contextSwitches = 0;

        $blockSeconds = 0;
        $blockEnd = null;
        $previousProject = null;

        $finishBlock = static function () use (&$blockSeconds, &$longestBlock, &$deepWorkSeconds, &$deepWorkBlocks): void {
            $longestBlock = max($longestBlock, $blockSeconds);

            if ($blockSeconds >= self::DEEP_WORK_MINIMUM_SECONDS) {
                $deepWorkSeconds += $blockSeconds;
                $deepWorkBlocks++;
            }
        };

        foreach ($durations as $duration) {
            $startsAt = $duration->started_at->getTimestamp();
            $endsAt = $startsAt + $duration->duration_seconds;
            $isContinuation = $blockEnd !== null && $startsAt - $blockEnd <= $duration->timeout_seconds;

            if ($isContinuation) {
                $blockSeconds += $duration->duration_seconds;

                if ($duration->project !== $previousProject) {
                    $contextSwitches++;
                }
            } else {
                if ($blockEnd !== null) {
                    $finishBlock();
                }

                $blockSeconds = $duration->duration_seconds;
            }

            $blockEnd = max($blockEnd ?? 0, $endsAt);
            $previousProject = $duration->project;
        }

        if ($blockEnd !== null) {
            $finishBlock();
        }

        return [
            'longest_block_seconds' => $longestBlock,
            'deep_work_seconds' => $deepWorkSeconds,
            'deep_work_blocks' => $deepWorkBlocks,
            'context_switches' => $contextSwitches,
        ];
    }

    /**
     * Streaks of consecutive days with at least STREAK_MINIMUM_SECONDS of
     * coding, computed over the last STREAK_WINDOW_DAYS regardless of the
     * selected range. A quiet today doesn't break the current streak — the
     * day isn't over yet. Covered days come from stored summaries; only the
     * uncovered tail touches durations.
     *
     * @return array{current_days: int, longest_days: int}
     */
    private static function streak(User $user, string $timezone, CarbonImmutable $today, ?CarbonImmutable $coveredUntil): array
    {
        $windowStart = $today->subDays(self::STREAK_WINDOW_DAYS);
        $hasStored = $coveredUntil !== null && $coveredUntil->greaterThanOrEqualTo($windowStart);
        $liveFrom = $hasStored ? $coveredUntil->addDay() : $windowStart;

        $durations = Duration::query()
            ->where('user_id', $user->id)
            ->where('started_at', '>=', $liveFrom->setTimezone('UTC'))
            ->get(['started_at', 'duration_seconds']);

        $perDay = self::mergeTotals(
            $hasStored ? self::storedSecondsPerDay($user, $windowStart, $coveredUntil) : [],
            self::secondsPerDay($durations, $timezone),
        );

        $activeDays = array_keys(array_filter(
            $perDay,
            static fn (int $seconds): bool => $seconds >= self::STREAK_MINIMUM_SECONDS,
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

    /**
     * Read/write mix and agent-file (plans, memory, instruction files)
     * activity from heartbeats. A null `is_write` is unknown and stays out of
     * every count, and agent-file lines come from write heartbeats only —
     * reading a plan isn't authoring it.
     *
     * @return array{write_events: int, read_events: int, agent_write_events: int, agent_lines: int}
     */
    private static function editing(User $user, CarbonImmutable $from, CarbonImmutable $today): array
    {
        $totals = Heartbeat::query()
            ->where('user_id', $user->id)
            ->where('recorded_at', '>=', $from->setTimezone('UTC'))
            ->where('recorded_at', '<', $today->addDay()->setTimezone('UTC'))
            ->selectRaw(
                'COUNT(CASE WHEN is_write = 1 THEN 1 END) AS write_events, '
                .'COUNT(CASE WHEN is_write = 0 THEN 1 END) AS read_events, '
                ."COUNT(CASE WHEN is_write = 1 AND entity_class = 'agent' THEN 1 END) AS agent_write_events, "
                ."COALESCE(SUM(CASE WHEN is_write = 1 AND entity_class = 'agent' "
                .'THEN COALESCE(ai_line_changes, 0) + COALESCE(human_line_changes, 0) END), 0) AS agent_lines'
            )
            ->first();

        return [
            'write_events' => (int) $totals->write_events,
            'read_events' => (int) $totals->read_events,
            'agent_write_events' => (int) $totals->agent_write_events,
            'agent_lines' => (int) $totals->agent_lines,
        ];
    }

    /**
     * AI authorship counts summed straight from heartbeats. Line changes are
     * signed nets (deletions can push them negative), and the ai/human columns
     * are disjoint by CLI-side dedup, so each sums independently. A prompt
     * event is a heartbeat carrying `ai_prompt_length`.
     *
     * @return array{ai_lines: int, human_lines: int, input_tokens: int, output_tokens: int, sessions: int, prompts: int, avg_prompt_length: int}
     */
    private static function aiTotals(User $user, CarbonImmutable $from, CarbonImmutable $today): array
    {
        $totals = Heartbeat::query()
            ->where('user_id', $user->id)
            ->where('recorded_at', '>=', $from->setTimezone('UTC'))
            ->where('recorded_at', '<', $today->addDay()->setTimezone('UTC'))
            ->selectRaw(
                'COALESCE(SUM(ai_line_changes), 0) AS ai_lines, '
                .'COALESCE(SUM(human_line_changes), 0) AS human_lines, '
                .'COALESCE(SUM(ai_input_tokens), 0) AS input_tokens, '
                .'COALESCE(SUM(ai_output_tokens), 0) AS output_tokens, '
                .'COUNT(DISTINCT ai_session) AS sessions, '
                .'COUNT(ai_prompt_length) AS prompts, '
                .'COALESCE(AVG(ai_prompt_length), 0) AS avg_prompt_length'
            )
            ->first();

        return [
            'ai_lines' => (int) $totals->ai_lines,
            'human_lines' => (int) $totals->human_lines,
            'input_tokens' => (int) $totals->input_tokens,
            'output_tokens' => (int) $totals->output_tokens,
            'sessions' => (int) $totals->sessions,
            'prompts' => (int) $totals->prompts,
            'avg_prompt_length' => (int) round((float) $totals->avg_prompt_length),
        ];
    }

    /**
     * Per-AI-agent totals, keyed by the model token parsed from each AI
     * heartbeat's User-Agent, with the estimated spend of each agent's
     * tokens. Heartbeats whose UA carries no model (e.g. AI activity relayed
     * under an editor plugin's UA) are omitted rather than misattributed.
     * Aggregated per distinct UA in SQL, then merged by model. Returns every
     * agent — display limiting is the caller's concern, so spend totals
     * cover them all.
     *
     * @return array<int, array{key: string, lines: int, input_tokens: int, output_tokens: int, sessions: int, cost_cents: int|null}>
     */
    private static function agents(User $user, CarbonImmutable $from, CarbonImmutable $today): array
    {
        $rows = Heartbeat::query()
            ->where('user_id', $user->id)
            ->where('recorded_at', '>=', $from->setTimezone('UTC'))
            ->where('recorded_at', '<', $today->addDay()->setTimezone('UTC'))
            ->where(function ($query) {
                $query->whereNotNull('ai_session')
                    ->orWhereNotNull('ai_line_changes')
                    ->orWhereNotNull('ai_input_tokens')
                    ->orWhereNotNull('ai_output_tokens');
            })
            ->groupBy('user_agent')
            ->selectRaw(
                // `lines` is reserved in MySQL, so alias as ai_lines.
                'user_agent, '
                .'COALESCE(SUM(ai_line_changes), 0) AS ai_lines, '
                .'COALESCE(SUM(ai_input_tokens), 0) AS input_tokens, '
                .'COALESCE(SUM(ai_output_tokens), 0) AS output_tokens, '
                .'COUNT(DISTINCT ai_session) AS sessions'
            )
            ->get();

        $agents = [];

        foreach ($rows as $row) {
            $model = UserAgentParser::aiModel($row->user_agent);

            if ($model === null) {
                continue;
            }

            $agent = $agents[$model] ?? ['key' => $model, 'lines' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'sessions' => 0];
            $agent['lines'] += (int) $row->ai_lines;
            $agent['input_tokens'] += (int) $row->input_tokens;
            $agent['output_tokens'] += (int) $row->output_tokens;
            $agent['sessions'] += (int) $row->sessions;
            $agents[$model] = $agent;
        }

        $costs = self::agentCosts($user, $from, $today);

        return collect($agents)
            ->map(static fn (array $agent): array => [
                ...$agent,
                'cost_cents' => $costs[$agent['key']] ?? null,
            ])
            ->sortByDesc('lines')
            ->values()
            ->all();
    }

    /**
     * Estimated cents per agent model, priced per day so a price change
     * mid-range applies only from its effective date. Cost days bucket in
     * UTC while dashboard days use the user's timezone — a few hours' drift
     * at a price boundary is immaterial for an estimate. Only models with at
     * least one priced day appear; tokens recorded before any price took
     * effect stay unpriced.
     *
     * @return array<string, int>
     */
    private static function agentCosts(User $user, CarbonImmutable $from, CarbonImmutable $today): array
    {
        $pricing = new AiPricing;

        $rows = Heartbeat::query()
            ->where('user_id', $user->id)
            ->where('recorded_at', '>=', $from->setTimezone('UTC'))
            ->where('recorded_at', '<', $today->addDay()->setTimezone('UTC'))
            ->where(function ($query) {
                $query->whereNotNull('ai_input_tokens')->orWhereNotNull('ai_output_tokens');
            })
            ->groupBy('user_agent')
            ->groupByRaw('DATE(recorded_at)')
            ->selectRaw(
                'user_agent, DATE(recorded_at) AS day, '
                .'COALESCE(SUM(ai_input_tokens), 0) AS input_tokens, '
                .'COALESCE(SUM(ai_output_tokens), 0) AS output_tokens'
            )
            ->get();

        $costs = [];

        foreach ($rows as $row) {
            $model = UserAgentParser::aiModel($row->user_agent);

            if ($model === null) {
                continue;
            }

            $cents = $pricing->costInCents($model, (int) $row->input_tokens, (int) $row->output_tokens, (string) $row->day);

            if ($cents === null) {
                continue;
            }

            $costs[$model] = ($costs[$model] ?? 0) + $cents;
        }

        return $costs;
    }

    /**
     * Total estimated spend across the priced agents, or null when no agent
     * has a price — unknown must not render as free.
     *
     * @param  array<int, array{cost_cents: int|null, ...}>  $agents
     */
    private static function estimatedCost(array $agents): ?int
    {
        $costs = array_filter(array_column($agents, 'cost_cents'), static fn (?int $cost): bool => $cost !== null);

        return $costs === [] ? null : array_sum($costs);
    }
}
