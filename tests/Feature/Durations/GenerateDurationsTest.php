<?php

use App\Actions\Durations\GenerateDurations;
use App\Models\Duration;
use App\Models\Heartbeat;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * @param  array<string, mixed>  $overrides
 */
function beat(User $user, CarbonImmutable $at, array $overrides = []): Heartbeat
{
    return Heartbeat::factory()->forUser($user)->create(array_merge([
        'project' => 'app',
        'language' => 'PHP',
        'editor' => 'phpstorm',
        'operating_system' => 'macos',
        'machine' => 'mac',
        'branch' => 'main',
        'category' => 'coding',
        'recorded_at' => $at,
    ], $overrides));
}

/**
 * @return Collection<int, Duration>
 */
function durationsFor(User $user)
{
    return Duration::query()
        ->where('user_id', $user->id)
        ->orderBy('started_at')
        ->get();
}

test('consecutive heartbeats within the timeout form one session', function () {
    $user = User::factory()->create();
    $base = CarbonImmutable::parse('2026-06-20 10:00:00', 'UTC');

    beat($user, $base);
    beat($user, $base->addMinutes(2));
    beat($user, $base->addMinutes(4));

    expect(app(GenerateDurations::class)->forUser($user))->toBe(1);

    $duration = durationsFor($user)->sole();

    expect($duration->duration_seconds)->toBe(240)
        ->and($duration->heartbeat_count)->toBe(3)
        ->and($duration->project)->toBe('app');
});

test('a gap of at least the timeout starts a new session', function () {
    $user = User::factory()->create();
    $base = CarbonImmutable::parse('2026-06-20 10:00:00', 'UTC');

    beat($user, $base);
    beat($user, $base->addMinutes(16));

    expect(app(GenerateDurations::class)->forUser($user))->toBe(2);

    expect(durationsFor($user)->pluck('duration_seconds')->all())->toBe([0, 0]);
});

test('a change of grouping key starts a new session and credits the gap to the previous one', function () {
    $user = User::factory()->create();
    $base = CarbonImmutable::parse('2026-06-20 10:00:00', 'UTC');

    beat($user, $base, ['project' => 'alpha']);
    beat($user, $base->addMinutes(2), ['project' => 'beta']);

    expect(app(GenerateDurations::class)->forUser($user))->toBe(2);

    $byProject = durationsFor($user)->keyBy('project');

    expect($byProject['alpha']->duration_seconds)->toBe(120)
        ->and($byProject['beta']->duration_seconds)->toBe(0);
});

test('a session never crosses a day boundary in the user timezone', function () {
    $user = User::factory()->create(['timezone' => 'UTC']);

    beat($user, CarbonImmutable::parse('2026-06-20 23:59:00', 'UTC'));
    beat($user, CarbonImmutable::parse('2026-06-21 00:01:00', 'UTC'));

    expect(app(GenerateDurations::class)->forUser($user))->toBe(2);
});

test('day boundaries are computed in the user timezone', function () {
    // Both instants are the same calendar day in UTC, but straddle midnight in
    // Auckland (UTC+12), so the timezone-aware split must produce two sessions.
    $first = CarbonImmutable::parse('2026-06-20 11:59:00', 'UTC');
    $second = CarbonImmutable::parse('2026-06-20 12:01:00', 'UTC');

    $utcUser = User::factory()->create(['timezone' => 'UTC']);
    beat($utcUser, $first);
    beat($utcUser, $second);

    $aucklandUser = User::factory()->create(['timezone' => 'Pacific/Auckland']);
    beat($aucklandUser, $first);
    beat($aucklandUser, $second);

    expect(app(GenerateDurations::class)->forUser($utcUser))->toBe(1)
        ->and(app(GenerateDurations::class)->forUser($aucklandUser))->toBe(2);
});

test('regeneration replaces existing durations and is idempotent', function () {
    $user = User::factory()->create();
    $base = CarbonImmutable::parse('2026-06-20 10:00:00', 'UTC');

    beat($user, $base);
    beat($user, $base->addMinutes(2));

    app(GenerateDurations::class)->forUser($user);
    app(GenerateDurations::class)->forUser($user);

    expect(durationsFor($user))->toHaveCount(1);
});

test('the configured heartbeat timeout changes how sessions are split', function () {
    config(['stats.heartbeat_timeout_sec' => 60]);

    $user = User::factory()->create();
    $base = CarbonImmutable::parse('2026-06-20 10:00:00', 'UTC');

    beat($user, $base);
    beat($user, $base->addMinutes(2));

    expect(app(GenerateDurations::class)->forUser($user))->toBe(2);
});

test('each user is sessionized independently', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $base = CarbonImmutable::parse('2026-06-20 10:00:00', 'UTC');

    beat($a, $base);
    beat($b, $base);

    app(GenerateDurations::class)->forUser($a);

    expect(durationsFor($a))->toHaveCount(1)
        ->and(durationsFor($b))->toHaveCount(0);
});
