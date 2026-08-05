<?php

use App\Models\DailyMetric;
use App\Models\Duration;
use App\Models\Heartbeat;
use App\Models\SummaryItem;
use App\Models\User;
use App\Stats\InsightsStats;
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

    $stats = app(InsightsStats::class)->build($user);

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

    $days = collect(app(InsightsStats::class)->build($user)['ai_calendar'])->keyBy('date');

    expect($days['2026-06-30'])->toBe(['date' => '2026-06-30', 'ai_lines' => 35, 'human_lines' => 3])
        ->and($days['2026-06-29'])->toBe(['date' => '2026-06-29', 'ai_lines' => 0, 'human_lines' => 0]);
});

test('weekday averages divide by weekday occurrences and break out ai time', function () {
    $user = User::factory()->create();

    // Two Mondays with an hour each, one of them AI coding.
    makeInsightsDuration($user, '2026-06-22 09:00:00', 3600);
    makeInsightsDuration($user, '2026-06-29 09:00:00', 3600, ['category' => 'ai coding']);

    $weekdays = collect(app(InsightsStats::class)->build($user)['weekdays'])->keyBy('label');

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

    $stats = app(InsightsStats::class)->build($user);

    expect(array_column($stats['top_ai_projects'], 'key'))->toBe(['beta', 'alpha'])
        ->and(array_column($stats['top_human_projects'], 'key'))->toBe(['gamma', 'alpha'])
        ->and($stats['top_ai_projects'][0]['ai_lines'])->toBe(200);
});

test('covered days read stored summaries and the tail stays live', function () {
    $user = User::factory()->create();
    $user->summaries_generated_until = '2026-06-29';
    $user->save();

    SummaryItem::create([
        'user_id' => $user->id,
        'day' => '2026-06-29',
        'type' => 'project',
        'key' => 'app',
        'total_seconds' => 600,
    ]);
    SummaryItem::create([
        'user_id' => $user->id,
        'day' => '2026-06-29',
        'type' => 'category',
        'key' => 'ai coding',
        'total_seconds' => 240,
    ]);

    // Ignored on a covered day: the stored rows are authoritative.
    makeInsightsDuration($user, '2026-06-29 09:00:00', 9999);

    makeInsightsDuration($user, '2026-06-30 09:00:00', 900, ['category' => 'ai coding']);

    $stats = app(InsightsStats::class)->build($user);
    $calendar = collect($stats['calendar'])->keyBy('date');
    $weekdays = collect($stats['weekdays'])->keyBy('label');

    // 2025-07-01..2026-06-30 holds 52 Mondays (the 29th) and 53 Tuesdays
    // (the 30th; the window starts and ends on one).
    expect($calendar['2026-06-29']['seconds'])->toBe(600)
        ->and($calendar['2026-06-30']['seconds'])->toBe(900)
        ->and($weekdays['Mon']['average_seconds'])->toBe(intdiv(600, 52))
        ->and($weekdays['Mon']['ai_average_seconds'])->toBe(intdiv(240, 52))
        ->and($weekdays['Tue']['average_seconds'])->toBe(intdiv(900, 53))
        ->and($weekdays['Tue']['ai_average_seconds'])->toBe(intdiv(900, 53));
});

test('the ai calendar and top projects merge stored metrics with the live tail', function () {
    $user = User::factory()->create();
    $user->summaries_generated_until = '2026-06-29';
    $user->save();

    DailyMetric::create([
        'user_id' => $user->id,
        'day' => '2026-06-29',
        'project' => 'alpha',
        'editor' => 'claude',
        'ai_lines' => 100,
        'human_lines' => 20,
    ]);

    // Ignored on a covered day: metrics come from storage.
    makeInsightsHeartbeat($user, '2026-06-29 09:00:00', ['project' => 'alpha', 'ai_line_changes' => 9999]);

    makeInsightsHeartbeat($user, '2026-06-30 09:00:00', ['project' => 'alpha', 'ai_line_changes' => 40]);
    makeInsightsHeartbeat($user, '2026-06-30 09:01:00', ['project' => 'beta', 'human_line_changes' => 10]);

    $stats = app(InsightsStats::class)->build($user);
    $days = collect($stats['ai_calendar'])->keyBy('date');

    expect($days['2026-06-29'])->toBe(['date' => '2026-06-29', 'ai_lines' => 100, 'human_lines' => 20])
        ->and($days['2026-06-30'])->toBe(['date' => '2026-06-30', 'ai_lines' => 40, 'human_lines' => 10])
        ->and(collect($stats['top_ai_projects'])->firstWhere('key', 'alpha'))
        ->toBe(['key' => 'alpha', 'ai_lines' => 140, 'human_lines' => 20])
        ->and(array_column($stats['top_human_projects'], 'key'))->toBe(['alpha', 'beta']);
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

    $files = app(InsightsStats::class)->build($user)['top_ai_files'];

    expect($files)->toHaveCount(1)
        ->and($files[0]['key'])->toBe('Widget.php')
        ->and($files[0]['path'])->toBe('/code/app/src/Widget.php')
        ->and($files[0]['project'])->toBe('app')
        ->and($files[0]['ai_lines'])->toBe(25);
});

test('a calendar year range spans that whole year and excludes other years', function () {
    $user = User::factory()->create();

    makeInsightsDuration($user, '2025-03-10 09:00:00', 3600);
    makeInsightsDuration($user, '2025-11-20 09:00:00', 1800);
    // Outside 2025 — must be excluded.
    makeInsightsDuration($user, '2026-06-30 09:00:00', 9999);

    $stats = app(InsightsStats::class)->build($user, '2025');

    $byDate = collect($stats['calendar'])->keyBy('date');

    expect($stats['range'])->toBe('2025')
        ->and($stats['from'])->toBe('2025-01-01')
        ->and($stats['to'])->toBe('2025-12-31')
        ->and($stats['calendar'])->toHaveCount(365)
        ->and(collect($stats['calendar'])->sum('seconds'))->toBe(5400)
        ->and($byDate['2025-03-10']['seconds'])->toBe(3600)
        ->and($byDate['2025-11-20']['seconds'])->toBe(1800);
});

test('available ranges list the trailing year then each year back to first activity', function () {
    $user = User::factory()->create();

    makeInsightsDuration($user, '2024-05-01 09:00:00', 60);
    makeInsightsDuration($user, '2026-06-30 09:00:00', 60);

    $stats = app(InsightsStats::class)->build($user);

    expect($stats['range'])->toBe('12m')
        ->and($stats['ranges'])->toBe(['12m', '2026', '2025', '2024']);
});

test('an unknown range falls back to the trailing year', function () {
    $user = User::factory()->create();

    makeInsightsDuration($user, '2026-06-30 09:00:00', 60);

    $stats = app(InsightsStats::class)->build($user, 'nonsense');

    expect($stats['range'])->toBe('12m')
        ->and($stats['from'])->toBe('2025-07-01')
        ->and($stats['to'])->toBe('2026-06-30');
});

test('top projects and files rank by time and work without line data', function () {
    $user = User::factory()->create();

    // Project time comes from durations (no line-authorship needed).
    makeInsightsDuration($user, '2026-06-29 09:00:00', 3600, ['project' => 'alpha']);
    makeInsightsDuration($user, '2026-06-29 11:00:00', 1200, ['project' => 'beta']);

    // File time is credited from the gap between two same-file heartbeats.
    makeInsightsHeartbeat($user, '2026-06-30 09:00:00', ['entity' => '/app/Big.php', 'entity_type' => 'file']);
    makeInsightsHeartbeat($user, '2026-06-30 09:02:00', ['entity' => '/app/Big.php', 'entity_type' => 'file']);

    $stats = app(InsightsStats::class)->build($user);

    expect($stats['top_projects'])->toBe([
        ['key' => 'alpha', 'seconds' => 3600],
        ['key' => 'beta', 'seconds' => 1200],
    ])
        ->and($stats['top_files'])->toBe([['key' => 'Big.php', 'seconds' => 120]]);
});
