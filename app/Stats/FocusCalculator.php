<?php

namespace App\Stats;

use App\Models\Duration;
use Illuminate\Database\Eloquent\Collection;

/**
 * Focus metrics derived from time-ordered durations merged into "blocks": a
 * duration continues the current block while the gap to the block's end stays
 * within the timeout, regardless of which grouping key changed.
 */
class FocusCalculator
{
    private const int DEEP_WORK_MINIMUM_SECONDS = 25 * 60;

    /**
     * @param  Collection<int, Duration>  $durations
     * @return array{longest_block_seconds: int, deep_work_seconds: int, deep_work_blocks: int, context_switches: int}
     */
    public function calculate(Collection $durations): array
    {
        $longestBlock = 0;
        $deepWorkSeconds = 0;
        $deepWorkBlocks = 0;
        $contextSwitches = 0;

        $blockSeconds = 0;
        $blockEnd = null;
        $previousProject = null;

        $finishBlock = function (int $seconds) use (&$longestBlock, &$deepWorkSeconds, &$deepWorkBlocks): void {
            $longestBlock = max($longestBlock, $seconds);

            if ($seconds >= self::DEEP_WORK_MINIMUM_SECONDS) {
                $deepWorkSeconds += $seconds;
                $deepWorkBlocks++;
            }
        };

        foreach ($durations as $duration) {
            $startsAt = $duration->started_at->getTimestamp();
            $endsAt = $startsAt + $duration->duration_seconds;
            $isContinuation = $blockEnd !== null && $startsAt - $blockEnd <= $duration->timeout_seconds;

            if ($isContinuation) {
                $blockSeconds += $duration->duration_seconds;

                // Context switches count project changes mid-block, not across breaks.
                if ($duration->project !== $previousProject) {
                    $contextSwitches++;
                }
            } else {
                if ($blockEnd !== null) {
                    $finishBlock($blockSeconds);
                }

                $blockSeconds = $duration->duration_seconds;
            }

            $blockEnd = max($blockEnd ?? 0, $endsAt);
            $previousProject = $duration->project;
        }

        if ($blockEnd !== null) {
            $finishBlock($blockSeconds);
        }

        return [
            'longest_block_seconds' => $longestBlock,
            'deep_work_seconds' => $deepWorkSeconds,
            'deep_work_blocks' => $deepWorkBlocks,
            'context_switches' => $contextSwitches,
        ];
    }
}
