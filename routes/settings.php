<?php

use App\Http\Controllers\Settings\AiPricingController;
use App\Http\Controllers\Settings\ApiKeyController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\TrackingController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    Route::get('settings/tracking', [TrackingController::class, 'edit'])->name('tracking.edit');
    Route::patch('settings/tracking', [TrackingController::class, 'update'])->name('tracking.update');
    Route::put('settings/api-key', [ApiKeyController::class, 'update'])->name('api-key.update');

    Route::get('settings/ai-pricing', [AiPricingController::class, 'edit'])->name('ai-pricing.edit');
    Route::post('settings/ai-pricing', [AiPricingController::class, 'store'])->name('ai-pricing.store');
    Route::patch('settings/ai-pricing/{price}', [AiPricingController::class, 'update'])->name('ai-pricing.update');
    Route::delete('settings/ai-pricing/{price}', [AiPricingController::class, 'destroy'])->name('ai-pricing.destroy');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
