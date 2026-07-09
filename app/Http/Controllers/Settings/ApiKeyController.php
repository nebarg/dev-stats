<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ApiKeyController extends Controller
{
    /**
     * Replace the user's API key; the old key stops authenticating immediately.
     */
    public function update(#[CurrentUser] User $user): RedirectResponse
    {
        $user->forceFill(['api_key' => (string) Str::uuid()])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('New API key generated.')]);

        return to_route('tracking.edit');
    }
}
