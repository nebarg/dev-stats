<?php

namespace Database\Factories;

use App\Models\AiPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiPrice>
 */
class AiPriceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'model_prefix' => fake()->unique()->word(),
            'input_price' => fake()->randomFloat(4, 0.1, 20),
            'output_price' => fake()->randomFloat(4, 1, 100),
            'effective_from' => fake()->dateTimeBetween('-1 year')->format('Y-m-d'),
        ];
    }
}
