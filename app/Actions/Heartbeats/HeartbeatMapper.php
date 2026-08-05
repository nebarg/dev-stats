<?php

namespace App\Actions\Heartbeats;

use App\Support\EntityClassifier;
use Illuminate\Support\Facades\Date;

/**
 * Maps a raw wakatime-cli payload onto heartbeat columns — resolving
 * placeholders and deriving category/entity class — and builds the content hash
 * that deduplicates heartbeats.
 */
class HeartbeatMapper
{
    private const array BROWSER_TYPES = ['url', 'domain'];

    /**
     * @return array<string, mixed>
     */
    public function attributes(HeartbeatContext $context, RawHeartbeat $payload, string $entity, float $time): array
    {
        $type = $payload->string('type') ?? 'file';
        $isBrowser = in_array($type, self::BROWSER_TYPES, true);

        $language = $this->resolve($payload->value('language'), $context->latest?->language, $isBrowser);

        return [
            'user_id' => $context->user->id,
            'entity' => $entity,
            'entity_type' => $type,
            'entity_class' => EntityClassifier::classify($entity, $type),
            'category' => $this->category($payload->value('category'), $isBrowser, $language),
            'project' => $this->resolve($payload->value('project'), $context->latest?->project, $isBrowser),
            'branch' => $this->resolve($payload->value('branch'), $context->latest?->branch, $isBrowser),
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
     * The identity hash two submissions must share to count as duplicates.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function hash(array $attributes): string
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

    /**
     * Browser heartbeats are always `browsing`; otherwise anything with a
     * resolved language is `coding`. An explicit, non-empty category wins.
     */
    private function category(mixed $value, bool $isBrowser, ?string $language): ?string
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
    private function resolve(mixed $value, ?string $previous, bool $isBrowser): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (! str_starts_with($value, '<<LAST_')) {
            return $value;
        }

        return $isBrowser ? null : $previous;
    }
}
