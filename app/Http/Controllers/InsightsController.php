<?php

namespace App\Http\Controllers;

use App\Actions\Stats\BuildInsightsStats;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Inertia\Inertia;
use Inertia\Response;

class InsightsController extends Controller
{
    public function index(#[CurrentUser] User $user): Response
    {
        return Inertia::render('Insights', [
            'stats' => BuildInsightsStats::forUser($user),
        ]);
    }
}
