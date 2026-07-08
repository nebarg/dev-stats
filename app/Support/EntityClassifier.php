<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Buckets a heartbeat's entity into a class: agent files (AI plans, memory,
 * instruction files) versus ordinary source. Stored on ingest so dashboard
 * aggregates stay plain SQL; `heartbeats:classify` reapplies the rules to
 * existing rows when they change.
 */
class EntityClassifier
{
    public const string AGENT = 'agent';

    public const string SOURCE = 'source';

    private const array AGENT_DIRECTORY_MARKERS = [
        '/.claude/',
        '/.cursor/',
        '/.aider',
        '/.github/copilot-',
    ];

    private const array AGENT_FILE_NAMES = [
        'claude.md',
        'claude.local.md',
        'agents.md',
        '.cursorrules',
        '.windsurfrules',
    ];

    public static function classify(?string $entity, ?string $entityType): ?string
    {
        if ($entity === null || $entity === '' || $entityType !== 'file') {
            return null;
        }

        $path = Str::lower(str_replace('\\', '/', $entity));

        if (Str::contains($path, self::AGENT_DIRECTORY_MARKERS)) {
            return self::AGENT;
        }

        if (in_array(basename($path), self::AGENT_FILE_NAMES, true)) {
            return self::AGENT;
        }

        return self::SOURCE;
    }
}
