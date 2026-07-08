<?php

namespace App\Models;

use Database\Factories\HeartbeatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Raw, append-only coding event from the wakatime-cli. Deduplicated on `hash`.
 *
 * @property int $id
 * @property int $user_id
 * @property string $entity
 * @property string $entity_type
 * @property string|null $entity_class
 * @property string|null $category
 * @property string|null $project
 * @property string|null $branch
 * @property string|null $language
 * @property array<int, string>|null $dependencies
 * @property bool|null $is_write
 * @property int|null $line_count
 * @property int|null $line_number
 * @property int|null $cursor_position
 * @property int|null $project_root_count
 * @property string|null $editor
 * @property string|null $operating_system
 * @property string|null $machine
 * @property string|null $user_agent
 * @property int|null $ai_line_changes
 * @property int|null $human_line_changes
 * @property string|null $ai_session
 * @property string|null $ai_subscription_plan
 * @property int|null $ai_input_tokens
 * @property int|null $ai_output_tokens
 * @property int|null $ai_prompt_length
 * @property Carbon $recorded_at
 * @property string $hash
 * @property Carbon|null $created_at
 */
#[Fillable([
    'user_id', 'entity', 'entity_type', 'entity_class', 'category', 'project',
    'branch', 'language', 'dependencies', 'is_write', 'line_count', 'line_number', 'cursor_position',
    'project_root_count', 'editor', 'operating_system', 'machine', 'user_agent',
    'ai_line_changes', 'human_line_changes', 'ai_session', 'ai_subscription_plan',
    'ai_input_tokens', 'ai_output_tokens', 'ai_prompt_length', 'recorded_at', 'hash',
])]
class Heartbeat extends Model
{
    /** @use HasFactory<HeartbeatFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

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
            'dependencies' => 'array',
            'is_write' => 'boolean',
            'line_count' => 'integer',
            'line_number' => 'integer',
            'cursor_position' => 'integer',
            'project_root_count' => 'integer',
            'ai_line_changes' => 'integer',
            'human_line_changes' => 'integer',
            'ai_input_tokens' => 'integer',
            'ai_output_tokens' => 'integer',
            'ai_prompt_length' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }
}
