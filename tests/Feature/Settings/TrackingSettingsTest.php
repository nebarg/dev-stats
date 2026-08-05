<?php

use App\Models\Duration;
use App\Models\Heartbeat;
use App\Models\SummaryItem;
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
    ]);

    $this->actingAs($user)
        ->get(route('tracking.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Tracking')
            ->where('timezone', 'Europe/London')
            ->where('apiKey', $user->api_key)
            ->has('timezones')
            ->has('apiUrl'));
});

test('tracking settings can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('tracking.update'), [
            'timezone' => 'Pacific/Auckland',
        ])
        ->assertRedirect(route('tracking.edit'));

    $user->refresh();

    expect($user->timezone)->toBe('Pacific/Auckland');
});

test('invalid timezones are rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('tracking.edit'))
        ->patch(route('tracking.update'), ['timezone' => 'Middle/Earth'])
        ->assertRedirect(route('tracking.edit'))
        ->assertSessionHasErrors('timezone');
});

test('changing the timezone wipes and rebuilds stored summaries', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-30 12:00:00', 'UTC'));
    $user = User::factory()->create(['timezone' => 'UTC']);

    Heartbeat::factory()->forUser($user)->create([
        'recorded_at' => CarbonImmutable::parse('2026-06-29 10:00:00', 'UTC'),
        'project' => 'app',
    ]);

    // A stale row bucketed under the old timezone; a rebuild never produces
    // this key.
    SummaryItem::create([
        'user_id' => $user->id,
        'day' => '2026-06-29',
        'type' => 'project',
        'key' => 'stale',
        'total_seconds' => 123,
    ]);
    $user->summaries_generated_until = '2026-06-29';
    $user->save();

    $this->actingAs($user)->patch(route('tracking.update'), [
        'timezone' => 'Pacific/Auckland',
    ]);

    $keys = SummaryItem::query()->where('user_id', $user->id)->where('type', 'project')->pluck('key');

    expect($keys->all())->toBe(['app'])
        ->and($user->fresh()->summaries_generated_until->toDateString())->toBe('2026-06-30');
});

test('an unchanged submission leaves durations untouched', function () {
    $user = User::factory()->create();

    Heartbeat::factory()->forUser($user)->create();

    $this->actingAs($user)->patch(route('tracking.update'), [
        'timezone' => $user->timezone,
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
