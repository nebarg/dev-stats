<?php

use App\Actions\Stats\BuildDashboardStats;
use App\Models\Duration;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * @param  array<string, mixed>  $overrides
 */
function makeDuration(User $user, CarbonImmutable $startedAt, int $seconds, array $overrides = []): Duration
{
    return Duration::create(array_merge([
        'user_id' => $user->id,
        'started_at' => $startedAt,
        'duration_seconds' => $seconds,
        'project' => 'app',
        'language' => 'PHP',
        'editor' => 'phpstorm',
        'operating_system' => 'macos',
        'machine' => 'mac',
        'branch' => 'main',
        'category' => 'coding',
        'heartbeat_count' => 1,
        'group_hash' => 'hash',
        'timeout_seconds' => 600,
    ], $overrides));
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-30 12:00:00', 'UTC'));
});

test('it totals durations in range and reports today separately', function () {
    $user = User::factory()->create();

    makeDuration($user, CarbonImmutable::parse('2026-06-30 09:00:00', 'UTC'), 3600);
    makeDuration($user, CarbonImmutable::parse('2026-06-28 09:00:00', 'UTC'), 1800);
    makeDuration($user, CarbonImmutable::parse('2026-06-10 09:00:00', 'UTC'), 9999);

    $stats = BuildDashboardStats::forUser($user, '7d');

    expect($stats['range'])->toBe('7d')
        ->and($stats['from'])->toBe('2026-06-24')
        ->and($stats['to'])->toBe('2026-06-30')
        ->and($stats['total_seconds'])->toBe(5400)
        ->and($stats['today_seconds'])->toBe(3600)
        ->and($stats['active_days'])->toBe(2)
        ->and($stats['daily_average_seconds'])->toBe(2700)
        ->and($stats['most_active_day'])->toBe(['date' => '2026-06-30', 'seconds' => 3600])
        ->and($stats['activity'])->toHaveCount(7)
        ->and($stats['activity'][6])->toBe(['date' => '2026-06-30', 'seconds' => 3600]);
});

test('it builds breakdowns sorted by total time descending', function () {
    $user = User::factory()->create();

    makeDuration($user, CarbonImmutable::parse('2026-06-30 09:00:00', 'UTC'), 600, ['project' => 'alpha']);
    makeDuration($user, CarbonImmutable::parse('2026-06-30 10:00:00', 'UTC'), 1200, ['project' => 'beta']);
    makeDuration($user, CarbonImmutable::parse('2026-06-29 10:00:00', 'UTC'), 300, ['project' => 'alpha']);

    $projects = BuildDashboardStats::forUser($user, '7d')['breakdowns']['projects'];

    expect($projects)->toBe([
        ['key' => 'beta', 'seconds' => 1200],
        ['key' => 'alpha', 'seconds' => 900],
    ]);
});

test('it labels empty project and language buckets and defaults unknown ranges', function () {
    $user = User::factory()->create();

    makeDuration($user, CarbonImmutable::parse('2026-06-30 09:00:00', 'UTC'), 600, [
        'project' => null,
        'language' => null,
    ]);

    $stats = BuildDashboardStats::forUser($user, 'nonsense');

    expect($stats['range'])->toBe('7d')
        ->and($stats['breakdowns']['projects'])->toBe([['key' => 'No project', 'seconds' => 600]])
        ->and($stats['breakdowns']['languages'])->toBe([['key' => 'AI Session', 'seconds' => 600]]);
});

test('it buckets days in the user timezone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-30 06:00:00', 'UTC'));
    $user = User::factory()->create(['timezone' => 'Pacific/Auckland']);

    // 13:00 UTC on the 29th is 01:00 on the 30th in Auckland (UTC+12) — i.e. "today".
    makeDuration($user, CarbonImmutable::parse('2026-06-29 13:00:00', 'UTC'), 600);

    $stats = BuildDashboardStats::forUser($user, '7d');

    expect($stats['today_seconds'])->toBe(600);
});

test('it returns zeroed stats with no durations', function () {
    $user = User::factory()->create();

    $stats = BuildDashboardStats::forUser($user);

    expect($stats['total_seconds'])->toBe(0)
        ->and($stats['daily_average_seconds'])->toBe(0)
        ->and($stats['active_days'])->toBe(0)
        ->and($stats['most_active_day'])->toBeNull()
        ->and($stats['activity'])->toHaveCount(7);
});
