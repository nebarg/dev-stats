<?php

use App\Actions\Stats\BuildDashboardStats;
use App\Models\Duration;
use App\Models\Heartbeat;
use App\Models\SummaryItem;
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

test('it treats range boundaries as half-open: start inclusive, end exclusive', function () {
    $user = User::factory()->create();

    // The 7d range on 2026-06-30 covers [2026-06-24 00:00:00, 2026-07-01 00:00:00).
    makeDuration($user, CarbonImmutable::parse('2026-06-23 23:59:59', 'UTC'), 111);
    makeDuration($user, CarbonImmutable::parse('2026-06-24 00:00:00', 'UTC'), 222);
    makeDuration($user, CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC'), 444);

    Heartbeat::factory()->forUser($user)->create([
        'recorded_at' => CarbonImmutable::parse('2026-06-24 00:00:00', 'UTC'),
        'ai_line_changes' => 10,
    ]);
    Heartbeat::factory()->forUser($user)->create([
        'recorded_at' => CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC'),
        'ai_line_changes' => 999,
    ]);

    $stats = BuildDashboardStats::forUser($user, '7d');

    expect($stats['total_seconds'])->toBe(222)
        ->and($stats['ai']['ai_lines'])->toBe(10);
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

test('it breaks down time by category', function () {
    $user = User::factory()->create();

    makeDuration($user, CarbonImmutable::parse('2026-06-30 09:00:00', 'UTC'), 1200, ['category' => 'ai coding']);
    makeDuration($user, CarbonImmutable::parse('2026-06-30 10:00:00', 'UTC'), 600, ['category' => 'coding']);
    makeDuration($user, CarbonImmutable::parse('2026-06-29 09:00:00', 'UTC'), 300, ['category' => null]);

    $categories = BuildDashboardStats::forUser($user, '7d')['breakdowns']['categories'];

    expect($categories)->toBe([
        ['key' => 'ai coding', 'seconds' => 1200],
        ['key' => 'coding', 'seconds' => 600],
        ['key' => 'Uncategorised', 'seconds' => 300],
    ]);
});

test('it sums ai line and token totals from heartbeats in range', function () {
    $user = User::factory()->create();

    Heartbeat::factory()->forUser($user)->create([
        'recorded_at' => CarbonImmutable::parse('2026-06-30 09:00:00', 'UTC'),
        'ai_line_changes' => 120,
        'human_line_changes' => 30,
        'ai_input_tokens' => 1000,
        'ai_output_tokens' => 200,
    ]);

    // Nets are signed (deletions) and null AI columns count as zero.
    Heartbeat::factory()->forUser($user)->create([
        'recorded_at' => CarbonImmutable::parse('2026-06-28 09:00:00', 'UTC'),
        'ai_line_changes' => -20,
        'human_line_changes' => null,
        'ai_input_tokens' => null,
        'ai_output_tokens' => null,
    ]);

    // Outside the 7-day range — must not count.
    Heartbeat::factory()->forUser($user)->create([
        'recorded_at' => CarbonImmutable::parse('2026-06-10 09:00:00', 'UTC'),
        'ai_line_changes' => 999,
        'human_line_changes' => 999,
        'ai_input_tokens' => 999,
        'ai_output_tokens' => 999,
    ]);

    expect(BuildDashboardStats::forUser($user, '7d')['ai'])->toBe([
        'ai_lines' => 100,
        'human_lines' => 30,
        'input_tokens' => 1000,
        'output_tokens' => 200,
        'sessions' => 0,
        'prompts' => 0,
        'avg_prompt_length' => 0,
        'estimated_cost_cents' => null,
        'agents' => [],
    ]);
});

test('it counts ai sessions and prompt events', function () {
    $user = User::factory()->create();

    Heartbeat::factory()->forUser($user)->create([
        'recorded_at' => CarbonImmutable::parse('2026-06-30 09:00:00', 'UTC'),
        'ai_session' => 'session-a',
        'ai_prompt_length' => 100,
    ]);

    Heartbeat::factory()->forUser($user)->create([
        'recorded_at' => CarbonImmutable::parse('2026-06-30 09:05:00', 'UTC'),
        'ai_session' => 'session-a',
        'ai_prompt_length' => 300,
    ]);

    // Same session id, no prompt — counts towards sessions only.
    Heartbeat::factory()->forUser($user)->create([
        'recorded_at' => CarbonImmutable::parse('2026-06-29 09:00:00', 'UTC'),
        'ai_session' => 'session-b',
    ]);

    $ai = BuildDashboardStats::forUser($user, '7d')['ai'];

    expect($ai['sessions'])->toBe(2)
        ->and($ai['prompts'])->toBe(2)
        ->and($ai['avg_prompt_length'])->toBe(200);
});

test('it breaks down ai activity by agent model from the user agent', function () {
    // Fixed prices so assertions don't track the shipped defaults; gpt-5 is
    // deliberately unpriced.
    config(['ai-pricing.models' => ['opus' => ['input' => 1000.0, 'output' => 2000.0]]]);

    $user = User::factory()->create();

    Heartbeat::factory()->forUser($user)->create([
        'recorded_at' => CarbonImmutable::parse('2026-06-30 09:00:00', 'UTC'),
        'user_agent' => 'opus/4.1-medium claude-code/2.1.45',
        'ai_session' => 'session-a',
        'ai_line_changes' => 120,
        'ai_input_tokens' => 1000,
        'ai_output_tokens' => 100,
    ]);

    Heartbeat::factory()->forUser($user)->create([
        'recorded_at' => CarbonImmutable::parse('2026-06-30 09:05:00', 'UTC'),
        'user_agent' => 'opus/4.1-medium claude-code/2.1.45',
        'ai_session' => 'session-a',
        'ai_line_changes' => 30,
    ]);

    Heartbeat::factory()->forUser($user)->create([
        'recorded_at' => CarbonImmutable::parse('2026-06-30 10:00:00', 'UTC'),
        'user_agent' => 'gpt-5/high codex/1.2.3',
        'ai_session' => 'session-b',
        'ai_line_changes' => 40,
    ]);

    // AI activity relayed under an editor plugin's UA carries no model — omitted.
    Heartbeat::factory()->forUser($user)->create([
        'recorded_at' => CarbonImmutable::parse('2026-06-30 11:00:00', 'UTC'),
        'ai_session' => 'session-c',
        'ai_line_changes' => 999,
    ]);

    $ai = BuildDashboardStats::forUser($user, '7d')['ai'];

    // Opus: (1000 in × $1000/M) + (100 out × $2000/M) = $1.20. The unpriced
    // gpt-5 agent carries no cost and stays out of the total — unknown
    // isn't free.
    expect($ai['agents'])->toBe([
        ['key' => 'opus/4.1-medium', 'lines' => 150, 'input_tokens' => 1000, 'output_tokens' => 100, 'sessions' => 1, 'cost_cents' => 120],
        ['key' => 'gpt-5/high', 'lines' => 40, 'input_tokens' => 0, 'output_tokens' => 0, 'sessions' => 1, 'cost_cents' => null],
    ])->and($ai['estimated_cost_cents'])->toBe(120);
});

test('it returns zeroed ai totals with no heartbeats', function () {
    $user = User::factory()->create();

    expect(BuildDashboardStats::forUser($user)['ai'])->toBe([
        'ai_lines' => 0,
        'human_lines' => 0,
        'input_tokens' => 0,
        'output_tokens' => 0,
        'sessions' => 0,
        'prompts' => 0,
        'avg_prompt_length' => 0,
        'estimated_cost_cents' => null,
        'agents' => [],
    ]);
});

test('it summarises write and read events and agent-file activity', function () {
    $user = User::factory()->create();
    $recordedAt = CarbonImmutable::parse('2026-06-30 09:00:00', 'UTC');

    Heartbeat::factory()->forUser($user)->create(['recorded_at' => $recordedAt, 'is_write' => true]);
    Heartbeat::factory()->forUser($user)->create(['recorded_at' => $recordedAt->addMinute(), 'is_write' => false]);

    // Unknown is_write stays out of every count.
    Heartbeat::factory()->forUser($user)->create(['recorded_at' => $recordedAt->addMinutes(2), 'is_write' => null]);

    Heartbeat::factory()->forUser($user)->create([
        'recorded_at' => $recordedAt->addMinutes(3),
        'is_write' => true,
        'entity_class' => 'agent',
        'ai_line_changes' => 40,
        'human_line_changes' => 5,
    ]);

    // Reading an agent file: no write event, no authored lines.
    Heartbeat::factory()->forUser($user)->create([
        'recorded_at' => $recordedAt->addMinutes(4),
        'is_write' => false,
        'entity_class' => 'agent',
        'ai_line_changes' => 99,
    ]);

    // Outside the 7-day range — must not count.
    Heartbeat::factory()->forUser($user)->create([
        'recorded_at' => CarbonImmutable::parse('2026-06-10 09:00:00', 'UTC'),
        'is_write' => true,
    ]);

    expect(BuildDashboardStats::forUser($user, '7d')['editing'])->toBe([
        'write_events' => 2,
        'read_events' => 2,
        'agent_write_events' => 1,
        'agent_lines' => 45,
    ]);
});

test('it merges nearby durations into focus blocks and counts mid-flow switches', function () {
    $user = User::factory()->create();

    // 09:00–09:10 app, a 5-minute pause, then 09:15–09:30 web: one merged
    // 25-minute block (deep work) with one project switch.
    makeDuration($user, CarbonImmutable::parse('2026-06-30 09:00:00', 'UTC'), 600, ['project' => 'app']);
    makeDuration($user, CarbonImmutable::parse('2026-06-30 09:15:00', 'UTC'), 900, ['project' => 'web']);

    // A two-hour gap breaks the block; this switch back doesn't count.
    makeDuration($user, CarbonImmutable::parse('2026-06-30 12:00:00', 'UTC'), 600, ['project' => 'app']);

    expect(BuildDashboardStats::forUser($user, '7d')['focus'])->toBe([
        'longest_block_seconds' => 1500,
        'deep_work_seconds' => 1500,
        'deep_work_blocks' => 1,
        'context_switches' => 1,
    ]);
});

test('it computes current and longest streaks of qualifying days', function () {
    $user = User::factory()->create();

    // Today and yesterday: the current streak.
    makeDuration($user, CarbonImmutable::parse('2026-06-30 09:00:00', 'UTC'), 1200);
    makeDuration($user, CarbonImmutable::parse('2026-06-29 09:00:00', 'UTC'), 1200);

    // A three-day run the week before: the longest streak.
    makeDuration($user, CarbonImmutable::parse('2026-06-25 09:00:00', 'UTC'), 1200);
    makeDuration($user, CarbonImmutable::parse('2026-06-24 09:00:00', 'UTC'), 1200);
    makeDuration($user, CarbonImmutable::parse('2026-06-23 09:00:00', 'UTC'), 1200);

    expect(BuildDashboardStats::forUser($user, '7d')['streak'])->toBe([
        'current_days' => 2,
        'longest_days' => 3,
    ]);
});

test('it keeps a streak alive on a quiet today and ignores days under the floor', function () {
    $user = User::factory()->create();

    // Today sits under the 15-minute floor: it doesn't qualify, but the
    // streak ending yesterday is still current — the day isn't over.
    makeDuration($user, CarbonImmutable::parse('2026-06-30 09:00:00', 'UTC'), 600);
    makeDuration($user, CarbonImmutable::parse('2026-06-29 09:00:00', 'UTC'), 1200);
    makeDuration($user, CarbonImmutable::parse('2026-06-28 09:00:00', 'UTC'), 1200);

    expect(BuildDashboardStats::forUser($user, '7d')['streak'])->toBe([
        'current_days' => 2,
        'longest_days' => 2,
    ]);
});

test('it reads stored summaries for covered days and live durations beyond them', function () {
    $user = User::factory()->create();
    $user->summaries_generated_until = '2026-06-29';
    $user->save();

    SummaryItem::create([
        'user_id' => $user->id,
        'day' => '2026-06-28',
        'type' => 'project',
        'key' => 'stored-app',
        'total_seconds' => 1800,
    ]);

    // A duration on a covered day: the stored summary is authoritative, so
    // this must not double-count.
    makeDuration($user, CarbonImmutable::parse('2026-06-28 09:00:00', 'UTC'), 999);

    makeDuration($user, CarbonImmutable::parse('2026-06-30 09:00:00', 'UTC'), 3600);

    $stats = BuildDashboardStats::forUser($user, '7d');

    expect($stats['total_seconds'])->toBe(5400)
        ->and($stats['today_seconds'])->toBe(3600)
        ->and(collect($stats['activity'])->firstWhere('date', '2026-06-28'))->toBe(['date' => '2026-06-28', 'seconds' => 1800])
        ->and($stats['breakdowns']['projects'])->toBe([
            ['key' => 'app', 'seconds' => 3600],
            ['key' => 'stored-app', 'seconds' => 1800],
        ]);
});

test('it merges stored and live totals for the same breakdown key', function () {
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

    makeDuration($user, CarbonImmutable::parse('2026-06-30 09:00:00', 'UTC'), 900);

    $stats = BuildDashboardStats::forUser($user, '7d');

    expect($stats['total_seconds'])->toBe(1500)
        ->and($stats['breakdowns']['projects'])->toBe([['key' => 'app', 'seconds' => 1500]]);
});

test('it computes streaks from stored summaries and live durations together', function () {
    $user = User::factory()->create();
    $user->summaries_generated_until = '2026-06-29';
    $user->save();

    foreach (['2026-06-28', '2026-06-29'] as $day) {
        SummaryItem::create([
            'user_id' => $user->id,
            'day' => $day,
            'type' => 'project',
            'key' => 'app',
            'total_seconds' => 1200,
        ]);
    }

    makeDuration($user, CarbonImmutable::parse('2026-06-30 09:00:00', 'UTC'), 1200);

    expect(BuildDashboardStats::forUser($user, '7d')['streak'])->toBe([
        'current_days' => 3,
        'longest_days' => 3,
    ]);
});

test('it returns zeroed stats with no durations', function () {
    $user = User::factory()->create();

    $stats = BuildDashboardStats::forUser($user);

    expect($stats['total_seconds'])->toBe(0)
        ->and($stats['daily_average_seconds'])->toBe(0)
        ->and($stats['active_days'])->toBe(0)
        ->and($stats['most_active_day'])->toBeNull()
        ->and($stats['activity'])->toHaveCount(7)
        ->and($stats['focus'])->toBe([
            'longest_block_seconds' => 0,
            'deep_work_seconds' => 0,
            'deep_work_blocks' => 0,
            'context_switches' => 0,
        ])
        ->and($stats['streak'])->toBe(['current_days' => 0, 'longest_days' => 0]);
});
