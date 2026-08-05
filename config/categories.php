<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Category display labels
    |--------------------------------------------------------------------------
    |
    | Heartbeat categories are stored as WakaTime sends them (lowercase, e.g.
    | "coding", "ai coding"). These overrides control how they read in category
    | breakdowns. "coding" is relabelled "Human coding" so it contrasts clearly
    | with "AI coding" — the two are disjoint buckets (a heartbeat is one or the
    | other), so the label is accurate. Anything without an override is simply
    | capitalised.
    |
    */

    'labels' => [
        'coding' => 'Human coding',
        'ai coding' => 'AI coding',
        'writing docs' => 'Writing docs',
        'writing tests' => 'Writing tests',
        'browsing' => 'Browsing',
        'debugging' => 'Debugging',
        'code reviewing' => 'Code reviewing',
    ],

];
