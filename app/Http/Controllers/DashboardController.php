<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Stats\DashboardStats;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(#[CurrentUser] User $user, Request $request, DashboardStats $stats): Response
    {
        return Inertia::render('Dashboard', [
            'stats' => $stats->build($user, $request->string('range')->toString()),
        ]);
    }
}
