<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Stats\ProjectStats;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function show(#[CurrentUser] User $user, Request $request, ProjectStats $stats, string $project): Response
    {
        abort_unless($user->durations()->where('project', $project)->exists(), 404);

        return Inertia::render('projects/Show', [
            'stats' => $stats->build($user, $project, $request->string('range')->toString()),
        ]);
    }
}
