<?php

use App\Models\Duration;
use App\Models\Heartbeat;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('tracking.edit'));
    $response->assertRedirect(route('login'));
});

test('the tracking page shares the current settings and api key', function () {
    $user = User::factory()->create([
        'timezone' => 'Europe/London',
        'heartbeat_timeout_sec' => 900,
    ]);

    $this->actingAs($user)
        ->get(route('tracking.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Tracking')
            ->where('timezone', 'Europe/London')
            ->where('heartbeatTimeoutSec', 900)
            ->where('apiKey', $user->api_key)
            ->has('timezones')
            ->has('apiUrl'));
});

test('tracking settings can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('tracking.update'), [
            'timezone' => 'Pacific/Auckland',
            'heartbeat_timeout_sec' => 300,
        ])
        ->assertRedirect(route('tracking.edit'));

    $user->refresh();

    expect($user->timezone)->toBe('Pacific/Auckland')
        ->and($user->heartbeat_timeout_sec)->toBe(300);
});

test('invalid timezones and out-of-range timeouts are rejected', function (array $input, string $field) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('tracking.edit'))
        ->patch(route('tracking.update'), array_merge([
            'timezone' => 'UTC',
            'heartbeat_timeout_sec' => 600,
        ], $input))
        ->assertRedirect(route('tracking.edit'))
        ->assertSessionHasErrors($field);
})->with([
    'made-up timezone' => [['timezone' => 'Middle/Earth'], 'timezone'],
    'timeout below a minute' => [['heartbeat_timeout_sec' => 30], 'heartbeat_timeout_sec'],
    'timeout above an hour' => [['heartbeat_timeout_sec' => 7200], 'heartbeat_timeout_sec'],
]);

test('changing the timeout regenerates durations from heartbeats', function () {
    $user = User::factory()->create(['heartbeat_timeout_sec' => 600]);

    Heartbeat::factory()->forUser($user)->create([
        'recorded_at' => CarbonImmutable::parse('2026-06-30 10:00:00', 'UTC'),
    ]);

    $this->actingAs($user)->patch(route('tracking.update'), [
        'timezone' => $user->timezone,
        'heartbeat_timeout_sec' => 300,
    ]);

    expect(Duration::query()->where('user_id', $user->id)->pluck('timeout_seconds')->all())
        ->toBe([300]);
});

test('an unchanged submission leaves durations untouched', function () {
    $user = User::factory()->create();

    Heartbeat::factory()->forUser($user)->create();

    $this->actingAs($user)->patch(route('tracking.update'), [
        'timezone' => $user->timezone,
        'heartbeat_timeout_sec' => $user->heartbeat_timeout_sec,
    ]);

    expect(Duration::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('a new api key can be generated', function () {
    $user = User::factory()->create();
    $originalKey = $user->api_key;

    $this->actingAs($user)
        ->put(route('api-key.update'))
        ->assertRedirect(route('tracking.edit'));

    $user->refresh();

    expect($user->api_key)->not->toBe($originalKey)
        ->and($user->api_key)->toBeUuid();
});
