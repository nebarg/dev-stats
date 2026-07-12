<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Model Pricing
    |--------------------------------------------------------------------------
    |
    | Estimated USD per one million tokens, keyed by a prefix of the model
    | token the wakatime-cli prepends to AI heartbeat User-Agents (an
    | "opus/4.1-medium" heartbeat matches the "opus/4.1" entry, falling back
    | to "opus"). The longest matching prefix wins. Owner-maintained: these
    | are estimates seeded from public list prices — update them as providers
    | change theirs. Unknown models are surfaced without a cost rather than
    | as a misleading zero.
    |
    */

    'models' => [
        'opus' => ['input' => 15.0, 'output' => 75.0],
        'sonnet' => ['input' => 3.0, 'output' => 15.0],
        'haiku' => ['input' => 1.0, 'output' => 5.0],
        'gpt-5' => ['input' => 1.25, 'output' => 10.0],
    ],

];
