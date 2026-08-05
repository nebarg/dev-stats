<?php

use App\Models\Duration;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('insights'));
    $response->assertRedirect(route('login'));
});

test('the insights page shares stats for the authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('insights'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Insights')
            ->has('stats.calendar', 365)
            ->has('stats.ai_calendar', 365)
            ->has('stats.weekdays', 7)
            ->has('stats.top_ai_projects')
            ->has('stats.top_human_projects')
            ->has('stats.top_ai_files')
            ->has('stats.top_human_files'));
});

test('the range query parameter selects a calendar year', function () {
    $user = User::factory()->create();

    Duration::create([
        'user_id' => $user->id,
        'started_at' => CarbonImmutable::parse('2025-04-01 09:00:00', 'UTC'),
        'duration_seconds' => 3600,
        'project' => 'app',
        'language' => 'PHP',
        'editor' => 'phpstorm',
        'operating_system' => 'macos',
        'machine' => 'mac',
        'branch' => 'main',
        'category' => 'coding',
        'heartbeat_count' => 1,
        'group_hash' => 'hash',
        'timeout_seconds' => 900,
    ]);

    $this->actingAs($user)
        ->get(route('insights', ['range' => '2025']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.range', '2025')
            ->where('stats.from', '2025-01-01')
            ->where('stats.to', '2025-12-31')
            ->has('stats.ranges'));
});
