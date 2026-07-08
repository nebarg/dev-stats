<?php

namespace Database\Factories;

use App\Models\Heartbeat;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Heartbeat>
 */
class HeartbeatFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'entity' => '/Users/dev/code/'.fake()->word().'/'.fake()->word().'.php',
            'entity_type' => 'file',
            'entity_class' => 'source',
            'category' => 'coding',
            'project' => fake()->word(),
            'branch' => fake()->randomElement(['main', 'develop', 'feature/x']),
            'language' => fake()->randomElement(['PHP', 'TypeScript', 'Go', 'Python', 'Vue']),
            'is_write' => fake()->boolean(),
            'line_count' => fake()->numberBetween(10, 2000),
            'line_number' => fake()->numberBetween(1, 500),
            'cursor_position' => fake()->numberBetween(0, 120),
            'editor' => fake()->randomElement(['vscode', 'phpstorm', 'neovim']),
            'operating_system' => fake()->randomElement(['macos', 'windows', 'linux']),
            'machine' => fake()->domainWord(),
            'user_agent' => 'wakatime/v1.0.0 (darwin-arm64) go1.22 vscode-wakatime/24.0.0',
            'recorded_at' => fake()->dateTimeBetween('-7 days'),
            'hash' => (string) Str::uuid(),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }
}
