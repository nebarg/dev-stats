<?php

namespace App\Models;

use App\Casts\AsCalendarDate;
use App\Models\Concerns\BelongsToUser;
use App\Models\Concerns\BucketedByDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Pre-aggregated AI authorship counts for one `(day, project, editor)`
 * bucket, in the user's timezone (a cache, regenerable from heartbeats).
 * Line changes are signed nets; the editor identifies the AI tool. Token and
 * prompt columns are stored now and surfaced later.
 *
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable $day
 * @property string|null $project
 * @property string|null $editor
 * @property int $ai_lines
 * @property int $human_lines
 * @property int $ai_input_tokens
 * @property int $ai_output_tokens
 * @property int $ai_prompts
 * @property int $ai_prompt_length
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id', 'day', 'project', 'editor', 'ai_lines', 'human_lines',
    'ai_input_tokens', 'ai_output_tokens', 'ai_prompts', 'ai_prompt_length',
])]
class DailyMetric extends Model
{
    use BelongsToUser;
    use BucketedByDay;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => AsCalendarDate::class,
            'ai_lines' => 'integer',
            'human_lines' => 'integer',
            'ai_input_tokens' => 'integer',
            'ai_output_tokens' => 'integer',
            'ai_prompts' => 'integer',
            'ai_prompt_length' => 'integer',
        ];
    }
}
