<?php

namespace App\Http\Controllers;

use App\Actions\Stats\BuildProjectStats;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function show(#[CurrentUser] User $user, Request $request, string $project): Response
    {
        abort_unless($user->durations()->where('project', $project)->exists(), 404);

        return Inertia::render('projects/Show', [
            'stats' => BuildProjectStats::forUser(
                $user,
                $project,
                $request->string('range')->toString(),
            ),
        ]);
    }
}
