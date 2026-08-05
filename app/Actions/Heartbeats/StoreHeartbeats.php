<?php

namespace App\Actions\Heartbeats;

use App\Actions\Summaries\InvalidateSummaries;
use App\Models\Heartbeat;
use App\Models\User;
use App\Support\EntityClassifier;
use App\Support\UserAgentParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * Maps wakatime-cli payloads onto our columns, resolves placeholders, dedups on
 * a content hash, and persists heartbeats. Returns one result per submitted
 * heartbeat, in order, for the bulk response.
 */
class StoreHeartbeats
{
    private const int FUTURE_TOLERANCE_SECONDS = 3600;

    private const array BROWSER_TYPES = ['url', 'domain'];

    /**
     * @param  array<int, mixed>  $rawHeartbeats
     * @return array<int, HeartbeatResult>
     */
    public static function handle(User $user, array $rawHeartbeats, ?string $userAgent, ?string $machine): array
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
            static fn (mixed $raw): HeartbeatResult => self::store($context, $raw),
            $rawHeartbeats,
        );

        self::invalidateStaleSummaries($user, $results);

        return $results;
    }

    /**
     * The CLI's offline queue delivers heartbeats late and out of order, so a
     * new heartbeat can land on an already-summarised day. Its stored
     * summaries are stale from that day on; discard them for regeneration.
     * Duplicates change nothing and don't invalidate.
     *
     * @param  array<int, HeartbeatResult>  $results
     */
    private static function invalidateStaleSummaries(User $user, array $results): void
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

        InvalidateSummaries::fromDay($user, $day);
    }

    private static function store(HeartbeatContext $context, mixed $raw): HeartbeatResult
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

        $attributes = self::attributes($context, $payload, $entity, $time);

        $heartbeat = Heartbeat::firstOrCreate(['hash' => self::hash($attributes)], $attributes);

        return HeartbeatResult::created($heartbeat, $time);
    }

    /**
     * @return array<string, mixed>
     */
    private static function attributes(HeartbeatContext $context, RawHeartbeat $payload, string $entity, float $time): array
    {
        $type = $payload->string('type') ?? 'file';
        $isBrowser = in_array($type, self::BROWSER_TYPES, true);

        $language = self::resolve($payload->value('language'), $context->latest?->language, $isBrowser);

        return [
            'user_id' => $context->user->id,
            'entity' => $entity,
            'entity_type' => $type,
            'entity_class' => EntityClassifier::classify($entity, $type),
            'category' => self::category($payload->value('category'), $isBrowser, $language),
            'project' => self::resolve($payload->value('project'), $context->latest?->project, $isBrowser),
            'branch' => self::resolve($payload->value('branch'), $context->latest?->branch, $isBrowser),
            'language' => $language,
            'dependencies' => $payload->stringList('dependencies'),
            'is_write' => $payload->boolean('is_write'),
            'line_count' => $payload->int('lines'),
            'line_number' => $payload->int('lineno'),
            'cursor_position' => $payload->int('cursorpos'),
            'project_root_count' => $payload->int('project_root_count'),
            'editor' => $context->editor,
            'operating_system' => $context->operatingSystem,
            'machine' => $context->machine,
            'user_agent' => $context->userAgent,
            'ai_line_changes' => $payload->int('ai_line_changes'),
            'human_line_changes' => $payload->int('human_line_changes'),
            'ai_session' => $payload->string('ai_session'),
            'ai_subscription_plan' => $payload->string('ai_subscription_plan'),
            'ai_input_tokens' => $payload->int('ai_input_tokens'),
            'ai_output_tokens' => $payload->int('ai_output_tokens'),
            'ai_prompt_length' => $payload->int('ai_prompt_length'),
            'recorded_at' => Date::createFromTimestampMs((int) round($time * 1000), 'UTC'),
        ];
    }

    /**
     * Browser heartbeats are always `browsing`; otherwise anything with a
     * resolved language is `coding`. An explicit, non-empty category wins.
     */
    private static function category(mixed $value, bool $isBrowser, ?string $language): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if ($isBrowser) {
            return 'browsing';
        }

        return $language !== null ? 'coding' : null;
    }

    /**
     * Resolve a possibly-placeholder value: a `<<LAST_*>>` token inherits from
     * the user's latest heartbeat, except for browser heartbeats which clear it.
     */
    private static function resolve(mixed $value, ?string $previous, bool $isBrowser): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (! str_starts_with($value, '<<LAST_')) {
            return $value;
        }

        return $isBrowser ? null : $previous;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function hash(array $attributes): string
    {
        $identity = [
            $attributes['user_id'],
            $attributes['entity'],
            $attributes['entity_type'],
            $attributes['category'],
            $attributes['project'],
            $attributes['branch'],
            $attributes['language'],
            match (true) {
                $attributes['is_write'] === true => '1',
                $attributes['is_write'] === false => '0',
                default => '',
            },
            $attributes['ai_session'],
            $attributes['recorded_at']->format('Y-m-d H:i:s.v'),
        ];

        return hash('sha256', implode('|', $identity));
    }
}
