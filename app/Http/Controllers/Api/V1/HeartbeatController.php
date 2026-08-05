<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Heartbeats\StoreHeartbeats;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\HeartbeatResultCollection;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HeartbeatController extends Controller
{
    public function store(#[CurrentUser] User $user, Request $request, StoreHeartbeats $store): JsonResponse
    {
        // The CLI posts a single heartbeat object or a list of them.
        $decoded = json_decode($request->getContent(), true);
        $payload = is_array($decoded) ? $decoded : [];
        $heartbeats = array_is_list($payload) ? $payload : [$payload];

        $results = $store->handle(
            $user,
            $heartbeats,
            $request->userAgent(),
            $request->header('X-Machine-Name'),
        );

        return new HeartbeatResultCollection($results)
            ->response()
            ->setStatusCode(201);
    }
}
