<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The dashboard reads today live from durations, so keep that cache close
// behind the incoming heartbeats. A full rebuild is cheap at current scale;
// switch to incremental regeneration when it stops being cheap.
Schedule::command('durations:generate')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Roll whole past days into summary_items/daily_metrics overnight; today is
// never persisted and is always computed live on read.
Schedule::command('summaries:generate')
    ->dailyAt('02:15')
    ->withoutOverlapping();
