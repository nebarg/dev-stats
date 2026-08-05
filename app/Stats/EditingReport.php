<?php

namespace App\Stats;

use App\Models\Heartbeat;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Read/write mix and agent-file (plans, memory, instruction files) activity
 * from heartbeats. A null `is_write` is unknown and stays out of every count;
 * agent-file lines come from write heartbeats only — reading a plan isn't
 * authoring it.
 */
class EditingReport
{
    /**
     * @return array{write_events: int, read_events: int, agent_write_events: int, agent_lines: int}
     */
    public function forUser(User $user, CarbonImmutable $from, CarbonImmutable $through): array
    {
        $totals = Heartbeat::query()
            ->forUser($user)
            ->recordedBetween($from, $through)
            ->toBase()
            ->selectRaw(
                'COUNT(CASE WHEN is_write = 1 THEN 1 END) AS write_events, '
                .'COUNT(CASE WHEN is_write = 0 THEN 1 END) AS read_events, '
                ."COUNT(CASE WHEN is_write = 1 AND entity_class = 'agent' THEN 1 END) AS agent_write_events, "
                ."COALESCE(SUM(CASE WHEN is_write = 1 AND entity_class = 'agent' "
                .'THEN COALESCE(ai_line_changes, 0) + COALESCE(human_line_changes, 0) END), 0) AS agent_lines'
            )
            ->first();

        return [
            'write_events' => (int) ($totals->write_events ?? 0),
            'read_events' => (int) ($totals->read_events ?? 0),
            'agent_write_events' => (int) ($totals->agent_write_events ?? 0),
            'agent_lines' => (int) ($totals->agent_lines ?? 0),
        ];
    }
}
