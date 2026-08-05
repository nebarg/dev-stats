<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Stats\InsightsStats;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InsightsController extends Controller
{
    public function index(#[CurrentUser] User $user, Request $request, InsightsStats $stats): Response
    {
        return Inertia::render('Insights', [
            'stats' => $stats->build($user, $request->string('range')->toString()),
        ]);
    }
}
