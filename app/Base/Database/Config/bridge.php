<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Diagnostic Capture Storage
    |--------------------------------------------------------------------------
    |
    | Laravel filesystem disk and path prefix where diagnostic row capture
    | packages are written. Must never be a publicly served disk: packages
    | contain raw (byte-exact) production row values apart from redacted
    | secret columns.
    |
    */
    'disk' => env('BRIDGE_DISK', 'local'),

    'path_prefix' => env('BRIDGE_PATH_PREFIX', 'bridge/diagnostics'),

    'incoming_path_prefix' => env('BRIDGE_INCOMING_PATH_PREFIX', 'bridge/incoming'),

    /*
    |--------------------------------------------------------------------------
    | Capture Limits
    |--------------------------------------------------------------------------
    |
    | Bounds applied before any row is serialized. Diagnostic capture is for
    | a handful of problem records plus their referenced parents, not bulk
    | data movement — the bridge dataset contract owns that.
    |
    */
    'limits' => [
        'max_selected_rows' => (int) env('BRIDGE_MAX_SELECTED_ROWS', 100),
        'max_tables' => (int) env('BRIDGE_MAX_TABLES', 100),
        'max_closure_rows' => (int) env('BRIDGE_MAX_CLOSURE_ROWS', 5000),
        'max_closure_depth' => (int) env('BRIDGE_MAX_CLOSURE_DEPTH', 8),
        'max_scalar_bytes' => (int) env('BRIDGE_MAX_SCALAR_BYTES', 5 * 1024 * 1024),
        'max_package_bytes' => (int) env('BRIDGE_MAX_PACKAGE_BYTES', 25 * 1024 * 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Explicit Redaction Rules
    |--------------------------------------------------------------------------
    |
    | Opaque Laravel runtime payloads can contain credentials even when the
    | column name is generic. Table owners may extend this map from their
    | service provider until the module-contributed classification registry
    | in the bridge plan is available.
    |
    */
    'redaction' => [
        'columns' => [
            'cache' => ['value'],
            'failed_jobs' => ['payload', 'exception'],
            'job_batches' => ['failed_job_ids', 'options'],
            'jobs' => ['payload'],
            'sessions' => ['payload'],
        ],
    ],
];
