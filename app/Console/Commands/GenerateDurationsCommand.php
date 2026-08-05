<?php

namespace App\Console\Commands;

use App\Actions\Durations\GenerateDurations;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('durations:generate {user? : Restrict regeneration to a single user id}')]
#[Description('Rebuild coding-session durations from raw heartbeats')]
class GenerateDurationsCommand extends Command
{
    public function handle(GenerateDurations $durations): int
    {
        $users = $this->argument('user') !== null
            ? User::where('id', $this->argument('user'))->get()
            : User::all();

        if ($users->isEmpty()) {
            $this->components->error('No matching users.');

            return self::FAILURE;
        }

        foreach ($users as $user) {
            $count = $durations->forUser($user);
            $this->components->info("Generated {$count} duration(s) for user {$user->id} ({$user->email}).");
        }

        return self::SUCCESS;
    }
}
