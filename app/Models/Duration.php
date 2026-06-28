<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
