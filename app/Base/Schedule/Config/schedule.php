<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Schedule run history retention
    |--------------------------------------------------------------------------
    |
    | Defaults for operator-editable keys in base_settings. keep_days is the
    | primary age cutoff; keep_count is a hard cap on newest rows kept after
    | age pruning. 0 disables that axis.
    |
    */
    'history' => [
        'keep_days' => 30,
        'keep_count' => 500,
    ],
];
