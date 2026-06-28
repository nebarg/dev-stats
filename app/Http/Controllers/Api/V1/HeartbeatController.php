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
    public function store(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        $heartbeats = array_is_list($payload) ? $payload : [$payload];

        $results = StoreHeartbeats::handle(
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
