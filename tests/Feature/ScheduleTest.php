<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

test('duration regeneration and the nightly summary rollup are scheduled', function () {
    // Booting the console kernel registers the schedule from routes/console.php.
    $this->artisan('schedule:list')->assertSuccessful();

    $events = collect(app(Schedule::class)->events());

    $durations = $events->sole(fn (Event $event): bool => str_contains((string) $event->command, 'durations:generate'));
    $summaries = $events->sole(fn (Event $event): bool => str_contains((string) $event->command, 'summaries:generate'));

    expect($durations->expression)->toBe('*/5 * * * *')
        ->and($durations->withoutOverlapping)->toBeTrue()
        ->and($summaries->expression)->toBe('15 2 * * *')
        ->and($summaries->withoutOverlapping)->toBeTrue();
});
