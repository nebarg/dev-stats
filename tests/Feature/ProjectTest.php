<?php

use App\Models\Duration;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function projectDurationFor(User $user, string $project = 'app'): Duration
{
    return Duration::create([
        'user_id' => $user->id,
        'started_at' => now()->subHour(),
        'duration_seconds' => 600,
        'project' => $project,
        'language' => 'PHP',
        'editor' => 'phpstorm',
        'operating_system' => 'macos',
        'machine' => 'mac',
        'branch' => 'main',
        'category' => 'coding',
        'heartbeat_count' => 1,
        'group_hash' => 'hash',
        'timeout_seconds' => 600,
    ]);
}

test('guests are redirected to the login page', function () {
    $response = $this->get(route('projects.show', ['project' => 'app']));
    $response->assertRedirect(route('login'));
});

test('a project the user has never worked on is not found', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('projects.show', ['project' => 'nope']))
        ->assertNotFound();
});

test('another user\'s project is not found', function () {
    $owner = User::factory()->create();
    projectDurationFor($owner, 'secret');

    $this->actingAs(User::factory()->create())
        ->get(route('projects.show', ['project' => 'secret']))
        ->assertNotFound();
});

test('the project page shares project stats', function () {
    $user = User::factory()->create();
    projectDurationFor($user);

    $this->actingAs($user)
        ->get(route('projects.show', ['project' => 'app']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/Show')
            ->where('stats.project', 'app')
            ->where('stats.range', '7d')
            ->has('stats.total_seconds')
            ->has('stats.activity', 7)
            ->has('stats.files')
            ->has('stats.file_count')
            ->has('stats.breakdowns.languages')
            ->has('stats.breakdowns.branches')
            ->has('stats.breakdowns.editors')
            ->has('stats.breakdowns.categories'));
});

test('the range query parameter selects the range', function () {
    $user = User::factory()->create();
    projectDurationFor($user);

    $this->actingAs($user)
        ->get(route('projects.show', ['project' => 'app', 'range' => '30d']))
        ->assertInertia(fn (Assert $page) => $page->where('stats.range', '30d'));
});
