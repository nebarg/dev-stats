<?php

use App\Actions\Heartbeats\StoreHeartbeats;
use App\Models\DailyMetric;
use App\Models\SummaryItem;
use App\Models\User;
use Carbon\CarbonImmutable;

function storedSummary(User $user, string $day, int $seconds = 600): SummaryItem
{
    return SummaryItem::create([
        'user_id' => $user->id,
        'day' => $day,
        'type' => 'project',
        'key' => 'app',
        'total_seconds' => $seconds,
    ]);
}

/**
 * @return array<string, mixed>
 */
function lateHeartbeatPayload(CarbonImmutable $recordedAt): array
{
    return [
        'entity' => '/Users/dev/code/app/late.php',
        'type' => 'file',
        'time' => (float) $recordedAt->getTimestamp(),
    ];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-30 12:00:00', 'UTC'));
});

test('a heartbeat for an already-summarised day discards summaries from that day onward', function () {
    $user = User::factory()->create();
    $user->summaries_generated_until = '2026-06-29';
    $user->save();

    storedSummary($user, '2026-06-27');
    storedSummary($user, '2026-06-28');
    storedSummary($user, '2026-06-29');
    DailyMetric::create(['user_id' => $user->id, 'day' => '2026-06-28', 'project' => 'app', 'ai_lines' => 10]);

    StoreHeartbeats::handle($user, [
        lateHeartbeatPayload(CarbonImmutable::parse('2026-06-28 10:00:00', 'UTC')),
    ], null, null);

    $remainingDays = SummaryItem::query()
        ->where('user_id', $user->id)
        ->get()
        ->map(fn (SummaryItem $item): string => $item->day->toDateString());

    expect($remainingDays->all())->toBe(['2026-06-27'])
        ->and(DailyMetric::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and($user->fresh()->summaries_generated_until->toDateString())->toBe('2026-06-27');
});

test('a heartbeat for today leaves stored summaries alone', function () {
    $user = User::factory()->create();
    $user->summaries_generated_until = '2026-06-29';
    $user->save();

    storedSummary($user, '2026-06-29');

    StoreHeartbeats::handle($user, [
        lateHeartbeatPayload(CarbonImmutable::parse('2026-06-30 10:00:00', 'UTC')),
    ], null, null);

    expect(SummaryItem::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($user->fresh()->summaries_generated_until->toDateString())->toBe('2026-06-29');
});

test('a duplicate of an already-stored heartbeat does not invalidate', function () {
    $user = User::factory()->create();
    $payload = lateHeartbeatPayload(CarbonImmutable::parse('2026-06-28 10:00:00', 'UTC'));

    // First delivery stores the heartbeat (nothing summarised yet, nothing to
    // invalidate). Summaries are then generated over it.
    StoreHeartbeats::handle($user, [$payload], null, null);

    $user->refresh();
    $user->summaries_generated_until = '2026-06-29';
    $user->save();
    storedSummary($user, '2026-06-28');

    // The offline queue re-delivers the same heartbeat: a dedup hit, so the
    // stored summaries still hold.
    StoreHeartbeats::handle($user, [$payload], null, null);

    expect(SummaryItem::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($user->fresh()->summaries_generated_until->toDateString())->toBe('2026-06-29');
});

test('lateness is judged against the day in the user timezone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-30 06:00:00', 'UTC'));
    $user = User::factory()->create(['timezone' => 'Pacific/Auckland']);
    $user->summaries_generated_until = '2026-06-29';
    $user->save();

    storedSummary($user, '2026-06-29');

    // 13:00 UTC on the 29th is already the 30th in Auckland — today there, so
    // not late despite matching the marker's UTC date.
    StoreHeartbeats::handle($user, [
        lateHeartbeatPayload(CarbonImmutable::parse('2026-06-29 13:00:00', 'UTC')),
    ], null, null);

    expect(SummaryItem::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($user->fresh()->summaries_generated_until->toDateString())->toBe('2026-06-29');
});
