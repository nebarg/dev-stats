<?php

use App\Actions\Stats\BuildInsightsStats;
use App\Models\Duration;
use App\Models\Heartbeat;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * @param  array<string, mixed>  $overrides
 */
function makeInsightsDuration(User $user, string $startedAt, int $seconds, array $overrides = []): Duration
{
    return Duration::create(array_merge([
        'user_id' => $user->id,
        'started_at' => CarbonImmutable::parse($startedAt, 'UTC'),
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

/**
 * @param  array<string, mixed>  $overrides
 */
function makeInsightsHeartbeat(User $user, string $recordedAt, array $overrides = []): Heartbeat
{
    return Heartbeat::factory()->forUser($user)->create(array_merge([
        'recorded_at' => CarbonImmutable::parse($recordedAt, 'UTC'),
        'project' => 'app',
        'ai_line_changes' => null,
        'human_line_changes' => null,
    ], $overrides));
}

beforeEach(function () {
    // A Tuesday.
    $this->travelTo(CarbonImmutable::parse('2026-06-30 12:00:00', 'UTC'));
});

test('the calendar spans a full trailing year with empty days filled', function () {
    $user = User::factory()->create();

    makeInsightsDuration($user, '2026-06-30 09:00:00', 3600);

    $stats = BuildInsightsStats::forUser($user);

    expect($stats['from'])->toBe('2025-07-01')
        ->and($stats['to'])->toBe('2026-06-30')
        ->and($stats['calendar'])->toHaveCount(365)
        ->and($stats['ai_calendar'])->toHaveCount(365)
        ->and(end($stats['calendar']))->toBe(['date' => '2026-06-30', 'seconds' => 3600])
        ->and($stats['calendar'][0]['seconds'])->toBe(0);
});

test('the ai calendar sums signed line changes per day in the user timezone', function () {
    $user = User::factory()->create(['timezone' => 'Pacific/Auckland']);

    // 13:00 UTC on the 29th is already the 30th in Auckland.
    makeInsightsHeartbeat($user, '2026-06-29 13:00:00', ['ai_line_changes' => 40, 'human_line_changes' => 3]);
    makeInsightsHeartbeat($user, '2026-06-29 14:00:00', ['ai_line_changes' => -5]);

    $days = collect(BuildInsightsStats::forUser($user)['ai_calendar'])->keyBy('date');

    expect($days['2026-06-30'])->toBe(['date' => '2026-06-30', 'ai_lines' => 35, 'human_lines' => 3])
        ->and($days['2026-06-29'])->toBe(['date' => '2026-06-29', 'ai_lines' => 0, 'human_lines' => 0]);
});

test('weekday averages divide by weekday occurrences and break out ai time', function () {
    $user = User::factory()->create();

    // Two Mondays with an hour each, one of them AI coding.
    makeInsightsDuration($user, '2026-06-22 09:00:00', 3600);
    makeInsightsDuration($user, '2026-06-29 09:00:00', 3600, ['category' => 'ai coding']);

    $weekdays = collect(BuildInsightsStats::forUser($user)['weekdays'])->keyBy('label');

    // 2025-07-01..2026-06-30 contains 52 Mondays.
    expect($weekdays)->toHaveCount(7)
        ->and($weekdays['Mon'])->toBe([
            'label' => 'Mon',
            'average_seconds' => intdiv(7200, 52),
            'ai_average_seconds' => intdiv(3600, 52),
        ])
        ->and($weekdays['Sun']['average_seconds'])->toBe(0);
});

test('top projects rank by the requested line column with positives only', function () {
    $user = User::factory()->create();

    makeInsightsHeartbeat($user, '2026-06-30 09:00:00', ['project' => 'alpha', 'ai_line_changes' => 100, 'human_line_changes' => 1]);
    makeInsightsHeartbeat($user, '2026-06-30 09:01:00', ['project' => 'beta', 'ai_line_changes' => 200]);
    makeInsightsHeartbeat($user, '2026-06-30 09:02:00', ['project' => 'gamma', 'ai_line_changes' => -10, 'human_line_changes' => 50]);

    $stats = BuildInsightsStats::forUser($user);

    expect(array_column($stats['top_ai_projects'], 'key'))->toBe(['beta', 'alpha'])
        ->and(array_column($stats['top_human_projects'], 'key'))->toBe(['gamma', 'alpha'])
        ->and($stats['top_ai_projects'][0]['ai_lines'])->toBe(200);
});

test('top files keep the full path and project behind a basename key', function () {
    $user = User::factory()->create();

    makeInsightsHeartbeat($user, '2026-06-30 09:00:00', [
        'entity' => '/code/app/src/Widget.php',
        'ai_line_changes' => 25,
    ]);
    makeInsightsHeartbeat($user, '2026-06-30 09:01:00', [
        'entity' => 'laravel.com',
        'entity_type' => 'domain',
        'ai_line_changes' => 999,
    ]);

    $files = BuildInsightsStats::forUser($user)['top_ai_files'];

    expect($files)->toHaveCount(1)
        ->and($files[0]['key'])->toBe('Widget.php')
        ->and($files[0]['path'])->toBe('/code/app/src/Widget.php')
        ->and($files[0]['project'])->toBe('app')
        ->and($files[0]['ai_lines'])->toBe(25);
});
