<?php

namespace App\Http\Controllers;

use App\Actions\Stats\BuildDashboardStats;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(#[CurrentUser] User $user, Request $request): Response
    {
        return Inertia::render('Dashboard', [
            'stats' => BuildDashboardStats::forUser(
                $user,
                $request->string('range')->toString(),
            ),
        ]);
    }
}
