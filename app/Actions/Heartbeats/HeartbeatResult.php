<?php

namespace App\Actions\Heartbeats;

use App\Models\Heartbeat;

/**
 * The outcome of storing a single heartbeat: the wakatime response body and
 * whether the heartbeat was stored.
 */
readonly class HeartbeatResult
{
    /**
     * @param  array<string, mixed>  $body
     */
    private function __construct(
        public array $body,
        private bool $isSuccessful,
    ) {}

    public static function created(Heartbeat $heartbeat, float $time): self
    {
        return new self([
            'data' => [
                'id' => (string) $heartbeat->id,
                'entity' => $heartbeat->entity,
                'type' => $heartbeat->entity_type,
                'time' => $time,
            ],
        ], true);
    }

    public static function rejected(string $error, ?string $entity = null): self
    {
        return new self(
            $entity === null ? ['error' => $error] : ['entity' => $entity, 'error' => $error],
            false,
        );
    }

    public function success(): bool
    {
        return $this->isSuccessful;
    }
}
