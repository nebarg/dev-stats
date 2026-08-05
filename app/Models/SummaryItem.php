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
 * One pre-aggregated day bucket: total coding seconds for a `(type, key)`
 * pair on a calendar day in the user's timezone (a cache, regenerable from
 * durations). Every type sums to the same grand total for a day, so headline
 * time reads one type; the others exist for breakdowns.
 *
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable $day
 * @property string $type
 * @property string|null $key
 * @property int $total_seconds
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'day', 'type', 'key', 'total_seconds'])]
class SummaryItem extends Model
{
    use BelongsToUser;
    use BucketedByDay;

    /**
     * Summary dimensions, matching the duration columns they bucket by. The
     * headline total for a day is the sum of any single type's items.
     */
    public const array TYPES = [
        'project', 'language', 'editor', 'operating_system', 'machine', 'branch', 'category',
    ];

    public const string HEADLINE_TYPE = 'project';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => AsCalendarDate::class,
            'total_seconds' => 'integer',
        ];
    }
}
