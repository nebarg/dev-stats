<?php

namespace App\Console\Commands;

use App\Actions\Durations\GenerateDurations;
use App\Actions\Summaries\GenerateSummaries;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('summaries:generate {user? : Restrict generation to a single user id}')]
#[Description('Regenerate durations and roll them up into daily summaries')]
class GenerateSummariesCommand extends Command
{
    public function handle(): int
    {
        $users = $this->argument('user') !== null
            ? User::where('id', $this->argument('user'))->get()
            : User::all();

        if ($users->isEmpty()) {
            $this->components->error('No matching users.');

            return self::FAILURE;
        }

        foreach ($users as $user) {
            GenerateDurations::forUser($user);
            $summary = GenerateSummaries::forUser($user);

            $this->components->info(
                "Summarised {$summary['days']} day(s) into {$summary['items']} summary item(s) "
                ."and {$summary['metrics']} daily metric(s) for user {$user->id} ({$user->email})."
            );
        }

        return self::SUCCESS;
    }
}
