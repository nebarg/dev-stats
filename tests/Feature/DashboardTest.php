<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('the dashboard shares stats for the authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('stats.range', '7d')
            ->has('stats.total_seconds')
            ->has('stats.activity', 7)
            ->has('stats.breakdowns.projects')
            ->has('stats.breakdowns.languages')
            ->has('stats.breakdowns.editors')
            ->has('stats.breakdowns.operating_systems'));
});

test('the range query parameter selects the range', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['range' => '30d']))
        ->assertInertia(fn (Assert $page) => $page->where('stats.range', '30d'));
});
