<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A pure calendar date (no time, no timezone), stored as `Y-m-d`. The
 * built-in date casts store `Y-m-d H:i:s`, which SQLite keeps verbatim —
 * breaking string comparisons against plain dates in queries and unique
 * rules. Storing the bare date keeps SQLite (tests) and MySQL DATE columns
 * (prod) comparing identically.
 *
 * @implements CastsAttributes<CarbonImmutable, CarbonImmutable|DateTimeInterface|string|null>
 */
class AsCalendarDate implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value !== null ? CarbonImmutable::parse((string) $value)->startOfDay() : null;
    }

    /**
     * A datetime keeps its own timezone's calendar date — an Auckland
     * midnight stores as the Auckland day.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return CarbonImmutable::parse((string) $value)->format('Y-m-d');
    }
}
