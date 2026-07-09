<?php

use App\Actions\Stats\BuildProjectStats;
use App\Models\Duration;
use App\Models\Heartbeat;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * @param  array<string, mixed>  $overrides
 */
function makeProjectDuration(User $user, CarbonImmutable $startedAt, int $seconds, array $overrides = []): Duration
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

/**
 * @param  array<string, mixed>  $overrides
 */
function makeProjectHeartbeat(User $user, string $recordedAt, string $entity, array $overrides = []): Heartbeat
{
    return Heartbeat::factory()->forUser($user)->create(array_merge([
        'recorded_at' => CarbonImmutable::parse($recordedAt, 'UTC'),
        'entity' => $entity,
        'entity_type' => 'file',
        'project' => 'app',
        'ai_line_changes' => null,
        'human_line_changes' => null,
    ], $overrides));
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-30 12:00:00', 'UTC'));
});

test('it totals only the requested project and scopes breakdowns to it', function () {
    $user = User::factory()->create();

    makeProjectDuration($user, CarbonImmutable::parse('2026-06-30 09:00:00', 'UTC'), 3600);
    makeProjectDuration($user, CarbonImmutable::parse('2026-06-28 09:00:00', 'UTC'), 1800, ['branch' => 'feature/x']);
    makeProjectDuration($user, CarbonImmutable::parse('2026-06-29 09:00:00', 'UTC'), 9999, ['project' => 'other']);

    $stats = BuildProjectStats::forUser($user, 'app', '7d');

    expect($stats['project'])->toBe('app')
        ->and($stats['total_seconds'])->toBe(5400)
        ->and($stats['today_seconds'])->toBe(3600)
        ->and($stats['active_days'])->toBe(2)
        ->and($stats['activity'])->toHaveCount(7)
        ->and($stats['breakdowns']['branches'])->toBe([
            ['key' => 'main', 'seconds' => 3600],
            ['key' => 'feature/x', 'seconds' => 1800],
        ]);
});

test('it credits within-timeout gaps to the file that opened them', function () {
    $user = User::factory()->create();

    makeProjectHeartbeat($user, '2026-06-30 10:00:00', '/code/app/src/A.php');
    makeProjectHeartbeat($user, '2026-06-30 10:05:00', '/code/app/src/A.php');
    makeProjectHeartbeat($user, '2026-06-30 10:07:00', '/code/app/src/B.php');
    // A ≥ 10-minute gap credits nothing.
    makeProjectHeartbeat($user, '2026-06-30 10:30:00', '/code/app/src/B.php');

    $files = BuildProjectStats::forUser($user, 'app', '7d')['files'];

    expect($files)->toBe([
        ['key' => 'A.php', 'path' => '/code/app/src/A.php', 'seconds' => 420, 'ai_lines' => 0, 'human_lines' => 0],
        ['key' => 'B.php', 'path' => '/code/app/src/B.php', 'seconds' => 0, 'ai_lines' => 0, 'human_lines' => 0],
    ]);
});

test('it does not credit time spent in another project between file heartbeats', function () {
    $user = User::factory()->create();

    makeProjectHeartbeat($user, '2026-06-30 10:00:00', '/code/app/src/A.php');
    makeProjectHeartbeat($user, '2026-06-30 10:02:00', '/code/other/X.php', ['project' => 'other']);
    makeProjectHeartbeat($user, '2026-06-30 10:04:00', '/code/app/src/A.php');

    $files = BuildProjectStats::forUser($user, 'app', '7d')['files'];

    // A is credited up to the switch away (120s), nothing while in `other`.
    expect($files)->toBe([
        ['key' => 'A.php', 'path' => '/code/app/src/A.php', 'seconds' => 120, 'ai_lines' => 0, 'human_lines' => 0],
    ]);
});

test('it lists only file entities but non-file heartbeats still close gaps', function () {
    $user = User::factory()->create();

    makeProjectHeartbeat($user, '2026-06-30 10:00:00', '/code/app/src/A.php');
    makeProjectHeartbeat($user, '2026-06-30 10:02:00', 'laravel.com', ['entity_type' => 'domain']);
    makeProjectHeartbeat($user, '2026-06-30 10:04:00', '/code/app/src/A.php');

    $files = BuildProjectStats::forUser($user, 'app', '7d')['files'];

    expect($files)->toBe([
        ['key' => 'A.php', 'path' => '/code/app/src/A.php', 'seconds' => 120, 'ai_lines' => 0, 'human_lines' => 0],
    ]);
});

test('it starts a fresh session across a day boundary in the user timezone', function () {
    $user = User::factory()->create();

    makeProjectHeartbeat($user, '2026-06-29 23:58:00', '/code/app/src/A.php');
    makeProjectHeartbeat($user, '2026-06-30 00:02:00', '/code/app/src/A.php');

    $files = BuildProjectStats::forUser($user, 'app', '7d')['files'];

    expect($files)->toBe([
        ['key' => 'A.php', 'path' => '/code/app/src/A.php', 'seconds' => 0, 'ai_lines' => 0, 'human_lines' => 0],
    ]);
});

test('it sums line changes per file and reports the total file count', function () {
    $user = User::factory()->create();

    makeProjectHeartbeat($user, '2026-06-30 10:00:00', '/code/app/src/A.php', ['ai_line_changes' => 40, 'human_line_changes' => 2]);
    makeProjectHeartbeat($user, '2026-06-30 10:01:00', '/code/app/src/A.php', ['ai_line_changes' => -5]);
    makeProjectHeartbeat($user, '2026-06-30 10:02:00', '/code/app/tests/B.php', ['human_line_changes' => 7]);

    $stats = BuildProjectStats::forUser($user, 'app', '7d');

    expect($stats['file_count'])->toBe(2)
        ->and($stats['files'][0])->toBe([
            'key' => 'src/A.php',
            'path' => '/code/app/src/A.php',
            'seconds' => 120,
            'ai_lines' => 35,
            'human_lines' => 2,
        ])
        ->and($stats['files'][1])->toBe([
            'key' => 'tests/B.php',
            'path' => '/code/app/tests/B.php',
            'seconds' => 0,
            'ai_lines' => 0,
            'human_lines' => 7,
        ]);
});

test('the all range starts at the first activity of this project only', function () {
    $user = User::factory()->create();

    makeProjectDuration($user, CarbonImmutable::parse('2026-06-20 09:00:00', 'UTC'), 600);
    makeProjectDuration($user, CarbonImmutable::parse('2026-05-01 09:00:00', 'UTC'), 600, ['project' => 'other']);

    $stats = BuildProjectStats::forUser($user, 'app', 'all');

    expect($stats['from'])->toBe('2026-06-20')
        ->and($stats['total_seconds'])->toBe(600);
});
