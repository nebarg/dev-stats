<?php

use App\Models\Heartbeat;
use App\Models\User;

test('a user is assigned a unique api key on creation', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    expect($a->api_key)->not->toBeNull()
        ->and($b->api_key)->not->toBeNull()
        ->and($a->api_key)->not->toBe($b->api_key);
});

test('settings default to UTC, Monday, and a 10 minute timeout', function () {
    $user = User::factory()->create();

    expect($user->timezone)->toBe('UTC')
        ->and($user->start_of_week)->toBe(1)
        ->and($user->heartbeat_timeout_sec)->toBe(600);
});

test('the api key is hidden from array and json output', function () {
    $user = User::factory()->create();

    expect($user->toArray())->not->toHaveKey('api_key');
});

test('a heartbeat belongs to its user', function () {
    $user = User::factory()->create();

    $heartbeat = Heartbeat::factory()->forUser($user)->create();

    expect($heartbeat->user_id)->toBe($user->id)
        ->and($heartbeat->user->is($user))->toBeTrue();
});
