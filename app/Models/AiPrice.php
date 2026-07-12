<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AiPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An owner-maintained price for AI tokens: estimated USD per one million
 * tokens for any model whose User-Agent token starts with `model_prefix`
 * (an "opus/4.1-medium" heartbeat matches an "opus/4.1" row, falling back
 * to "opus"). A row applies from `effective_from` until a newer row for the
 * same prefix supersedes it, so historical spend keeps its historical price.
 *
 * @property int $id
 * @property string $model_prefix
 * @property float $input_price
 * @property float $output_price
 * @property CarbonImmutable $effective_from
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['model_prefix', 'input_price', 'output_price', 'effective_from'])]
class AiPrice extends Model
{
    /** @use HasFactory<AiPriceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_price' => 'float',
            'output_price' => 'float',
            'effective_from' => 'immutable_date',
        ];
    }
}
