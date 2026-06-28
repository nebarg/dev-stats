<?php

namespace App\Actions\Heartbeats;

use App\Models\Heartbeat;
use App\Models\User;

/**
 * The batch-invariant context for a heartbeat submission: the owner, the agent
 * and machine it came from, and the user's latest stored heartbeat (used to
 * resolve `<<LAST_*>>` placeholders). Built once per request and shared by every
 * heartbeat in the bulk payload.
 */
readonly class HeartbeatContext
{
    public function __construct(
        public User $user,
        public ?Heartbeat $latest,
        public ?string $editor,
        public ?string $operatingSystem,
        public ?string $userAgent,
        public ?string $machine,
    ) {}
}
