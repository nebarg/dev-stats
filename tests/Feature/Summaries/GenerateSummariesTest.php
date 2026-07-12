<?php

use App\Actions\Summaries\GenerateSummaries;
use App\Models\DailyMetric;
use App\Models\Duration;
use App\Models\Heartbeat;
use App\Models\SummaryItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * @param  array<string, mixed>  $overrides
 */
function summaryDuration(User $user, CarbonImmutable $startedAt, int $seconds, array $overrides = []): Duration
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
 * @return Collection<int, SummaryItem>
 */
function summaryItemsFor(User $user): Collection
{
    return SummaryItem::query()
        ->where('user_id', $user->id)
        ->orderBy('day')
        ->orderBy('type')
        ->get();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-30 12:00:00', 'UTC'));
});

test('it rolls durations into per-day summary items up to yesterday and sets the marker', function () {
    $user = User::factory()->create();

    // A heartbeat anchors the first summarisable day.
    Heartbeat::factory()->forUser($user)->create(['recorded_at' => CarbonImmutable::parse('2026-06-28 09:00:00', 'UTC')]);

    summaryDuration($user, CarbonImmutable::parse('2026-06-28 09:00:00', 'UTC'), 600, ['project' => 'alpha']);
    summaryDuration($user, CarbonImmutable::parse('2026-06-29 09:00:00', 'UTC'), 300, ['project' => 'alpha']);
    summaryDuration($user, CarbonImmutable::parse('2026-06-29 10:00:00', 'UTC'), 900, ['project' => 'beta', 'language' => 'Vue']);

    // Today must never be persisted.
    summaryDuration($user, CarbonImmutable::parse('2026-06-30 09:00:00', 'UTC'), 9999);

    $result = GenerateSummaries::forUser($user);

    expect($result['days'])->toBe(2)
        ->and($user->fresh()->summaries_generated_until->toDateString())->toBe('2026-06-29');

    $items = summaryItemsFor($user);

    expect($items->filter(fn (SummaryItem $item): bool => $item->day->toDateString() === '2026-06-30'))->toHaveCount(0);

    $yesterday = $items->filter(fn (SummaryItem $item): bool => $item->day->toDateString() === '2026-06-29');
    $projects = $yesterday->where('type', 'project')->pluck('total_seconds', 'key');

    expect($projects->all())->toBe(['alpha' => 300, 'beta' => 900]);

    // Each duration lands in exactly one bucket per type, so every type sums
    // to the same day total.
    $languages = $yesterday->where('type', 'language');

    expect($languages->sum('total_seconds'))->toBe(1200)
        ->and($languages->pluck('total_seconds', 'key')->all())->toBe(['PHP' => 300, 'Vue' => 900]);
});

test('it buckets days in the user timezone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-30 06:00:00', 'UTC'));
    $user = User::factory()->create(['timezone' => 'Pacific/Auckland']);

    // 13:00 UTC on the 28th is 01:00 on the 29th in Auckland (UTC+12).
    Heartbeat::factory()->forUser($user)->create(['recorded_at' => CarbonImmutable::parse('2026-06-28 13:00:00', 'UTC')]);
    summaryDuration($user, CarbonImmutable::parse('2026-06-28 13:00:00', 'UTC'), 600);

    GenerateSummaries::forUser($user);

    expect(summaryItemsFor($user)->pluck('day')->unique()->map->toDateString()->all())->toBe(['2026-06-29']);
});

test('it stores null keys for durations without a dimension value', function () {
    $user = User::factory()->create();

    Heartbeat::factory()->forUser($user)->create(['recorded_at' => CarbonImmutable::parse('2026-06-29 09:00:00', 'UTC')]);
    summaryDuration($user, CarbonImmutable::parse('2026-06-29 09:00:00', 'UTC'), 600, ['project' => null, 'branch' => null]);

    GenerateSummaries::forUser($user);

    $items = summaryItemsFor($user);

    expect($items->where('type', 'project')->sole()->key)->toBeNull()
        ->and($items->where('type', 'branch')->sole()->total_seconds)->toBe(600);
});

test('it resumes from the marker and re-running adds nothing', function () {
    $user = User::factory()->create();

    Heartbeat::factory()->forUser($user)->create(['recorded_at' => CarbonImmutable::parse('2026-06-28 09:00:00', 'UTC')]);
    summaryDuration($user, CarbonImmutable::parse('2026-06-28 09:00:00', 'UTC'), 600);

    $first = GenerateSummaries::forUser($user);
    $countAfterFirst = SummaryItem::count();

    $second = GenerateSummaries::forUser($user->fresh());

    expect($first['days'])->toBe(2)
        ->and($second)->toBe(['days' => 0, 'items' => 0, 'metrics' => 0])
        ->and(SummaryItem::count())->toBe($countAfterFirst);
});

test('it summarises only days after the marker on later runs', function () {
    $user = User::factory()->create();

    Heartbeat::factory()->forUser($user)->create(['recorded_at' => CarbonImmutable::parse('2026-06-28 09:00:00', 'UTC')]);
    summaryDuration($user, CarbonImmutable::parse('2026-06-28 09:00:00', 'UTC'), 600);

    GenerateSummaries::forUser($user);

    // A day passes; yesterday's activity becomes summarisable.
    $this->travelTo(CarbonImmutable::parse('2026-07-01 12:00:00', 'UTC'));
    summaryDuration($user, CarbonImmutable::parse('2026-06-30 09:00:00', 'UTC'), 300);

    $result = GenerateSummaries::forUser($user->fresh());

    expect($result['days'])->toBe(1)
        ->and($user->fresh()->summaries_generated_until->toDateString())->toBe('2026-06-30')
        ->and(
            summaryItemsFor($user)
                ->where('type', 'project')
                ->mapWithKeys(fn (SummaryItem $item): array => [$item->day->toDateString() => $item->total_seconds])
                ->all()
        )
        ->toBe(['2026-06-28' => 600, '2026-06-30' => 300]);
});

test('it rolls ai heartbeat counts into daily metrics by project and editor', function () {
    $user = User::factory()->create();
    $recordedAt = CarbonImmutable::parse('2026-06-29 09:00:00', 'UTC');

    Heartbeat::factory()->forUser($user)->create([
        'recorded_at' => $recordedAt,
        'project' => 'app',
        'editor' => 'claude',
        'ai_line_changes' => 120,
        'human_line_changes' => null,
        'ai_input_tokens' => 1000,
        'ai_output_tokens' => 200,
        'ai_prompt_length' => 100,
    ]);

    Heartbeat::factory()->forUser($user)->create([
        'recorded_at' => $recordedAt->addMinutes(5),
        'project' => 'app',
        'editor' => 'claude',
        'ai_line_changes' => -20,
        'human_line_changes' => 5,
        'ai_input_tokens' => null,
        'ai_output_tokens' => null,
        'ai_prompt_length' => 300,
    ]);

    Heartbeat::factory()->forUser($user)->create([
        'recorded_at' => $recordedAt->addMinutes(10),
        'project' => 'web',
        'editor' => 'vscode',
        'human_line_changes' => 40,
        'ai_line_changes' => null,
        'ai_input_tokens' => null,
        'ai_output_tokens' => null,
        'ai_prompt_length' => null,
    ]);

    // No AI authorship columns at all: contributes no metric row.
    Heartbeat::factory()->forUser($user)->create([
        'recorded_at' => $recordedAt->addMinutes(15),
        'project' => 'plain',
        'ai_line_changes' => null,
        'human_line_changes' => null,
        'ai_input_tokens' => null,
        'ai_output_tokens' => null,
        'ai_prompt_length' => null,
    ]);

    GenerateSummaries::forUser($user);

    $metrics = DailyMetric::query()->where('user_id', $user->id)->orderBy('project')->get();

    expect($metrics)->toHaveCount(2);

    $app = $metrics->firstWhere('project', 'app');

    expect($app->editor)->toBe('claude')
        ->and($app->day->toDateString())->toBe('2026-06-29')
        ->and($app->ai_lines)->toBe(100)
        ->and($app->human_lines)->toBe(5)
        ->and($app->ai_input_tokens)->toBe(1000)
        ->and($app->ai_output_tokens)->toBe(200)
        ->and($app->ai_prompts)->toBe(2)
        ->and($app->ai_prompt_length)->toBe(400);

    expect($metrics->firstWhere('project', 'web')->human_lines)->toBe(40);
});

test('it does nothing for a user without heartbeats', function () {
    $user = User::factory()->create();

    expect(GenerateSummaries::forUser($user))->toBe(['days' => 0, 'items' => 0, 'metrics' => 0])
        ->and($user->fresh()->summaries_generated_until)->toBeNull();
});

test('the command regenerates durations before summarising', function () {
    $user = User::factory()->create();
    $base = CarbonImmutable::parse('2026-06-29 09:00:00', 'UTC');

    // Heartbeats only, sharing one grouping key so they form a single
    // two-minute session — the command must sessionize them first.
    $group = [
        'project' => 'app',
        'language' => 'PHP',
        'editor' => 'phpstorm',
        'operating_system' => 'macos',
        'machine' => 'mac',
        'branch' => 'main',
        'category' => 'coding',
    ];

    Heartbeat::factory()->forUser($user)->create([...$group, 'recorded_at' => $base]);
    Heartbeat::factory()->forUser($user)->create([...$group, 'recorded_at' => $base->addMinutes(2)]);

    $this->artisan('summaries:generate')->assertSuccessful();

    expect(Duration::query()->where('user_id', $user->id)->count())->toBeGreaterThan(0)
        ->and(summaryItemsFor($user)->where('type', 'project')->sum('total_seconds'))->toBeGreaterThan(0);
});
