<?php

namespace App\Http\Resources\Api\V1;

use App\Actions\Heartbeats\HeartbeatResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Renders one heartbeat outcome as a wakatime bulk-response pair: the response
 * body and its per-item status, 201 when the heartbeat was stored and 400 when
 * it was rejected.
 *
 * @mixin HeartbeatResult
 */
class HeartbeatResultResource extends JsonResource
{
    /**
     * @return array{0: array<string, mixed>, 1: int}
     */
    public function toArray(Request $request): array
    {
        /** @var HeartbeatResult $result */
        $result = $this->resource;

        return [
            $result->body,
            $result->success() ? 201 : 400,
        ];
    }
}
