<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Keystroke timeout
    |--------------------------------------------------------------------------
    |
    | How long a pause between heartbeats may last before it ends a coding
    | session. Gaps shorter than this count as continuous work; a longer gap
    | starts a new session. This is the core sessionization knob, so it is
    | application-wide rather than per-user — every user's totals are measured
    | the same way, which keeps cross-user comparisons honest. WakaTime defaults
    | to 15 minutes.
    |
    */

    'heartbeat_timeout_sec' => (int) env('STATS_HEARTBEAT_TIMEOUT_SEC', 900),

];
