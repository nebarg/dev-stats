<?php

namespace App\Models\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A pre-aggregated table keyed by calendar `day` (stored as Y-m-d in the
 * user's timezone). `forDayRange` spans whole days inclusively; the half-open
 * upper bound clears SQLite's time-suffixed date casts that an inclusive
 * `Y-m-d` comparison would miss.
 */
trait BucketedByDay
{
    /**
     * @param  Builder<static>  $query
     */
    public function scopeForDayRange(Builder $query, CarbonImmutable $fromDay, CarbonImmutable $throughDay): void
    {
        $query->where('day', '>=', $fromDay->toDateString())
            ->where('day', '<', $throughDay->addDay()->toDateString());
    }
}
