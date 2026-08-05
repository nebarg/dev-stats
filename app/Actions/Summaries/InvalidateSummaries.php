<?php

namespace App\Actions\Summaries;

use App\Models\DailyMetric;
use App\Models\SummaryItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Discards stored summaries that new information has made stale, so the next
 * `summaries:generate` run rebuilds them. Reads fall back to live computation
 * for the uncovered days in the meantime.
 */
class InvalidateSummaries
{
    /**
     * Invalidate the given user-timezone calendar day and everything after
     * it — used when a heartbeat arrives for an already-summarised day.
     */
    public function fromDay(User $user, CarbonImmutable $day): void
    {
        $marker = $user->summaries_generated_until;

        if ($marker === null || $marker->toDateString() < $day->toDateString()) {
            return;
        }

        DB::transaction(static function () use ($user, $day): void {
            SummaryItem::query()->forUser($user)->where('day', '>=', $day->toDateString())->delete();
            DailyMetric::query()->forUser($user)->where('day', '>=', $day->toDateString())->delete();

            $user->summaries_generated_until = $day->subDay();
            $user->save();
        });
    }

    /**
     * Wipe every stored summary — used when a setting that shapes them
     * (timezone, timeout) changes and day buckets no longer line up.
     */
    public function all(User $user): void
    {
        DB::transaction(static function () use ($user): void {
            SummaryItem::query()->forUser($user)->delete();
            DailyMetric::query()->forUser($user)->delete();

            $user->summaries_generated_until = null;
            $user->save();
        });
    }
}
