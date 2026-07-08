<?php

use App\Models\Heartbeat;
use App\Models\User;

test('it reapplies the classification rules to stored heartbeats', function () {
    $user = User::factory()->create();

    $agent = Heartbeat::factory()->forUser($user)->create([
        'entity' => '/Users/dev/.claude/projects/x/memory/notes.md',
        'entity_class' => null,
    ]);

    $source = Heartbeat::factory()->forUser($user)->create([
        'entity' => '/Users/dev/code/app/app/Models/User.php',
        'entity_class' => null,
    ]);

    $this->artisan('heartbeats:classify')
        ->expectsOutputToContain('Reclassified 2 heartbeat(s).')
        ->assertSuccessful();

    expect($agent->refresh()->entity_class)->toBe('agent')
        ->and($source->refresh()->entity_class)->toBe('source');
});
