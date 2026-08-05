<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A contiguous coding session derived from heartbeats (a cache, regenerable
 * from scratch). Heartbeats within a group are squashed while consecutive gaps
 * stay under the user's timeout and don't cross a day boundary.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $started_at
 * @property int $duration_seconds
 * @property string|null $project
 * @property string|null $language
 * @property string|null $editor
 * @property string|null $operating_system
 * @property string|null $machine
 * @property string|null $branch
 * @property string|null $category
 * @property int $heartbeat_count
 * @property string $group_hash
 * @property int $timeout_seconds
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id', 'started_at', 'duration_seconds', 'project',
    'language', 'editor', 'operating_system', 'machine', 'branch', 'category',
    'heartbeat_count', 'group_hash', 'timeout_seconds',
])]
class Duration extends Model
{
    use BelongsToUser;

    /**
     * Sessions started from `$fromDay` through the end of `$throughDay`, both
     * day-anchored in the user's timezone and compared as UTC instants. An
     * open `$throughDay` leaves the range unbounded above.
     *
     * @param  Builder<static>  $query
     */
    public function scopeStartedBetween(Builder $query, CarbonImmutable $fromDay, ?CarbonImmutable $throughDay = null): void
    {
        $query->where('started_at', '>=', $fromDay->setTimezone('UTC'));

        if ($throughDay !== null) {
            $query->where('started_at', '<', $throughDay->addDay()->setTimezone('UTC'));
        }
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'duration_seconds' => 'integer',
            'heartbeat_count' => 'integer',
            'timeout_seconds' => 'integer',
        ];
    }
}
