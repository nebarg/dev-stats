<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Durations\GenerateDurations;
use App\Actions\Summaries\GenerateSummaries;
use App\Actions\Summaries\InvalidateSummaries;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\TrackingUpdateRequest;
use App\Models\User;
use DateTimeZone;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TrackingController extends Controller
{
    /**
     * Show the user's tracking settings page.
     */
    public function edit(#[CurrentUser] User $user): Response
    {
        return Inertia::render('settings/Tracking', [
            'timezone' => $user->timezone,
            'heartbeatTimeoutSec' => $user->heartbeat_timeout_sec,
            'timezones' => DateTimeZone::listIdentifiers(),
            'apiKey' => $user->api_key,
            'apiUrl' => url('/api/v1'),
        ]);
    }

    /**
     * Update the user's tracking settings.
     */
    public function update(TrackingUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->fill($request->validated());

        // Both settings shape sessionization (timeout caps gaps, timezone sets
        // day boundaries), so stored durations and the summaries rolled up
        // from them go stale when either changes.
        $isRegenerationNeeded = $user->isDirty(['timezone', 'heartbeat_timeout_sec']);

        $user->save();

        if ($isRegenerationNeeded) {
            InvalidateSummaries::all($user);
            GenerateDurations::forUser($user);
            GenerateSummaries::forUser($user);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tracking settings updated.')]);

        return to_route('tracking.edit');
    }
}
