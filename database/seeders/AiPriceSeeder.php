<?php

namespace Database\Seeders;

use App\Models\AiPrice;
use Illuminate\Database\Seeder;

class AiPriceSeeder extends Seeder
{
    /**
     * Starter prices (estimated USD per one million tokens) seeded from
     * public list prices — amend them in Settings → AI pricing as providers
     * change theirs.
     */
    public function run(): void
    {
        $prices = [
            'opus' => ['input' => 15.0, 'output' => 75.0],
            'sonnet' => ['input' => 3.0, 'output' => 15.0],
            'haiku' => ['input' => 1.0, 'output' => 5.0],
            'gpt-5' => ['input' => 1.25, 'output' => 10.0],
        ];

        foreach ($prices as $prefix => $price) {
            AiPrice::firstOrCreate(
                ['model_prefix' => $prefix, 'effective_from' => '2025-01-01'],
                ['input_price' => $price['input'], 'output_price' => $price['output']],
            );
        }
    }
}
