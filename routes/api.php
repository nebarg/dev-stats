<?php

use App\Http\Controllers\Api\V1\HeartbeatController;
use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticateApiKey::class)->prefix('v1')->group(function () {
    Route::post('users/current/heartbeats.bulk', [HeartbeatController::class, 'store']);
    Route::post('users/current/heartbeats', [HeartbeatController::class, 'store']);
});
