<?php

namespace App\Stats;

use App\Models\Heartbeat;
use App\Models\User;
use App\Support\AiPricing;
use App\Support\UserAgentParser;
use Carbon\CarbonImmutable;

/**
 * The dashboard's AI block: authorship line/token totals, per-model agent
 * rollups, and estimated spend. Line changes are signed nets (deletions can
 * push them negative); the ai/human columns are disjoint by CLI-side dedup, so
 * each sums independently.
 */
class AiUsageReport
{
    private const int AGENT_LIMIT = 8;

    public function __construct(private readonly AiPricing $pricing) {}

    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user, CarbonImmutable $from, CarbonImmutable $through): array
    {
        $agents = $this->agents($user, $from, $through);

        return [
            ...$this->totals($user, $from, $through),
            'estimated_cost_cents' => $this->estimatedCost($agents),
            'agents' => array_slice($agents, 0, self::AGENT_LIMIT),
        ];
    }

    /**
     * AI authorship counts summed straight from heartbeats. A prompt event is a
     * heartbeat carrying `ai_prompt_length`.
     *
     * @return array{ai_lines: int, human_lines: int, input_tokens: int, output_tokens: int, sessions: int, prompts: int, avg_prompt_length: int}
     */
    private function totals(User $user, CarbonImmutable $from, CarbonImmutable $through): array
    {
        $totals = Heartbeat::query()
            ->forUser($user)
            ->recordedBetween($from, $through)
            ->toBase()
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
            'ai_lines' => (int) ($totals->ai_lines ?? 0),
            'human_lines' => (int) ($totals->human_lines ?? 0),
            'input_tokens' => (int) ($totals->input_tokens ?? 0),
            'output_tokens' => (int) ($totals->output_tokens ?? 0),
            'sessions' => (int) ($totals->sessions ?? 0),
            'prompts' => (int) ($totals->prompts ?? 0),
            'avg_prompt_length' => (int) round((float) ($totals->avg_prompt_length ?? 0)),
        ];
    }

    /**
     * Per-model totals with each model's estimated spend, keyed by the model
     * token parsed from each AI heartbeat's User-Agent. Heartbeats whose UA
     * carries no model are omitted rather than misattributed. Every agent is
     * returned — display limiting is the caller's concern.
     *
     * @return array<int, array{key: string, lines: int, input_tokens: int, output_tokens: int, sessions: int, cost_cents: int|null}>
     */
    private function agents(User $user, CarbonImmutable $from, CarbonImmutable $through): array
    {
        $rows = Heartbeat::query()
            ->forUser($user)
            ->recordedBetween($from, $through)
            ->where(function ($query) {
                $query->whereNotNull('ai_session')
                    ->orWhereNotNull('ai_line_changes')
                    ->orWhereNotNull('ai_input_tokens')
                    ->orWhereNotNull('ai_output_tokens');
            })
            ->groupBy('user_agent')
            ->toBase()
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

        $costs = $this->agentCosts($user, $from, $through);

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
     * Estimated cents per model, priced per day so a mid-range price change
     * applies only from its effective date. Cost days bucket in UTC while the
     * dashboard buckets in the user's timezone — a few hours' drift at a price
     * boundary is immaterial for an estimate. Tokens recorded before any price
     * took effect stay unpriced.
     *
     * @return array<string, int>
     */
    private function agentCosts(User $user, CarbonImmutable $from, CarbonImmutable $through): array
    {
        $rows = Heartbeat::query()
            ->forUser($user)
            ->recordedBetween($from, $through)
            ->where(function ($query) {
                $query->whereNotNull('ai_input_tokens')->orWhereNotNull('ai_output_tokens');
            })
            ->groupBy('user_agent')
            ->groupByRaw('DATE(recorded_at)')
            ->toBase()
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

            $cents = $this->pricing->costInCents($model, (int) $row->input_tokens, (int) $row->output_tokens, (string) $row->day);

            if ($cents === null) {
                continue;
            }

            $costs[$model] = ($costs[$model] ?? 0) + $cents;
        }

        return $costs;
    }

    /**
     * Total estimated spend across the priced agents, or null when no agent has
     * a price — unknown must not render as free.
     *
     * @param  array<int, array{cost_cents: int|null, ...}>  $agents
     */
    private function estimatedCost(array $agents): ?int
    {
        $costs = array_filter(array_column($agents, 'cost_cents'), static fn (?int $cost): bool => $cost !== null);

        return $costs === [] ? null : array_sum($costs);
    }
}
