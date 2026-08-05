<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Language breakdown "Other" bucket
    |--------------------------------------------------------------------------
    |
    | Editor plugins report a "language" per heartbeat, but some send a file
    | type or placeholder rather than a real programming language — JetBrains,
    | for example, sends ".env file" and "GitIgnore file". WakaTime's own
    | canonical list runs to 900+ names, so rather than allow-list every
    | language we deny-list the handful of non-languages here and fold them
    | (plus durations that carry no language at all) into a single "Other"
    | bucket in language breakdowns.
    |
    */

    'other_label' => 'Other',

    /*
     | Exact language names treated as non-languages. Matched case-insensitively
     | against the value the editor reported.
     */
    'non_languages' => [
        'text',
        'plain text',
        'plain_text',
        'plaintext',
        'ignore list',
        'requirements',
        'editorconfig',
        'tsconfig',
        'dotenv',
        'log',
        'binary',
        'other',
        'unknown',
    ],

    /*
     | Regex patterns treated as non-languages. Catches JetBrains' "<name> file"
     | file-type labels such as ".env file" and "GitIgnore file" without having
     | to enumerate each one.
     */
    'non_language_patterns' => [
        '/ file$/i',
    ],

];
