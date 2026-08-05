<?php

namespace App\Actions\Heartbeats;

use App\Actions\Summaries\InvalidateSummaries;
use App\Models\Heartbeat;
use App\Models\User;
use App\Support\UserAgentParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * Dedups wakatime-cli payloads on a content hash and persists heartbeats,
 * delegating the column mapping to HeartbeatMapper. Returns one result per
 * submitted heartbeat, in order, for the bulk response.
 */
class StoreHeartbeats
{
    private const int FUTURE_TOLERANCE_SECONDS = 3600;

    public function __construct(
        private readonly HeartbeatMapper $mapper,
        private readonly InvalidateSummaries $invalidate,
    ) {}

    /**
     * @param  array<int, mixed>  $rawHeartbeats
     * @return array<int, HeartbeatResult>
     */
    public function handle(User $user, array $rawHeartbeats, ?string $userAgent, ?string $machine): array
    {
        ['editor' => $editor, 'operating_system' => $operatingSystem] = UserAgentParser::parse($userAgent);

        $context = new HeartbeatContext(
            user: $user,
            latest: $user->heartbeats()->latest('recorded_at')->first(),
            editor: $editor,
            operatingSystem: $operatingSystem,
            userAgent: $userAgent,
            machine: $machine,
        );

        $results = array_map(
            fn (mixed $raw): HeartbeatResult => $this->store($context, $raw),
            $rawHeartbeats,
        );

        $this->invalidateStaleSummaries($user, $results);

        return $results;
    }

    private function store(HeartbeatContext $context, mixed $raw): HeartbeatResult
    {
        if (! is_array($raw)) {
            return HeartbeatResult::rejected('invalid heartbeat');
        }

        $payload = new RawHeartbeat($raw);

        $entity = $payload->string('entity');
        $time = $payload->float('time');

        if ($entity === null || $time === null) {
            return HeartbeatResult::rejected('invalid heartbeat');
        }

        if ($time - Date::now()->getTimestamp() > self::FUTURE_TOLERANCE_SECONDS) {
            return HeartbeatResult::rejected('time is too far in the future', $entity);
        }

        $attributes = $this->mapper->attributes($context, $payload, $entity, $time);

        $heartbeat = Heartbeat::firstOrCreate(['hash' => $this->mapper->hash($attributes)], $attributes);

        return HeartbeatResult::created($heartbeat, $time);
    }

    /**
     * The CLI's offline queue delivers heartbeats late and out of order, so a
     * new heartbeat can land on an already-summarised day, making its stored
     * summaries stale from that day on — discard them for regeneration.
     * Duplicates change nothing and don't invalidate.
     *
     * @param  array<int, HeartbeatResult>  $results
     */
    private function invalidateStaleSummaries(User $user, array $results): void
    {
        if ($user->summaries_generated_until === null) {
            return;
        }

        $earliest = null;

        foreach ($results as $result) {
            if ($result->heartbeat?->wasRecentlyCreated !== true) {
                continue;
            }

            if ($earliest === null || $result->heartbeat->recorded_at->lessThan($earliest)) {
                $earliest = $result->heartbeat->recorded_at;
            }
        }

        if ($earliest === null) {
            return;
        }

        $day = CarbonImmutable::parse($earliest->setTimezone($user->timezone)->toDateString());

        $this->invalidate->fromDay($user, $day);
    }
}
