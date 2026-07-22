<?php

return [
    'instance' => [
        'id' => null,
        'name' => null,
        'role' => null,
    ],

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
    'disk' => 'local',

    'path_prefix' => 'data-share/diagnostics',

    'incoming_path_prefix' => 'data-share/incoming',

    'outgoing_path_prefix' => 'data-share/outgoing',

    'receiving_path_prefix' => 'data-share/receiving',

    'mirror' => [
        'temp_path' => storage_path('app/private/data-share/mirror'),
        'timeout_seconds' => 3600,
        'catalog_cache_seconds' => 300,
        'lock_timeout_ms' => 30000,
        'executables' => [
            'pg_dump' => env('DATA_SHARE_MIRROR_PG_DUMP'),
            'psql' => env('DATA_SHARE_MIRROR_PSQL'),
        ],
    ],

    'offers' => [
        'expiry_minutes' => 60,
        'fetch_timeout_seconds' => 600,
        'base_urls' => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | Capture Limits
    |--------------------------------------------------------------------------
    |
    | Bounds applied before any row is serialized. Diagnostic capture is for
    | a handful of problem records plus their referenced parents, not bulk
    | data movement — Data Export uses a separate, larger limit set.
    |
    */
    'limits' => [
        'max_selected_rows' => 100,
        'max_tables' => 100,
        'max_closure_rows' => 5000,
        'max_closure_depth' => 8,
        'max_scalar_bytes' => 5 * 1024 * 1024,
        'max_package_bytes' => 25 * 1024 * 1024,
    ],

    'transfer_limits' => [
        'max_tables' => 250,
        'max_records' => 250000,
        'max_scalar_bytes' => 10 * 1024 * 1024,
        'max_record_line_bytes' => 32 * 1024 * 1024,
        'max_package_bytes' => 250 * 1024 * 1024,
        'incoming_retention_days' => 14,
        'receiving_retention_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Explicit Redaction Rules
    |--------------------------------------------------------------------------
    |
    | Opaque Laravel runtime payloads can contain credentials even when the
    | column name is generic. These Base-owned rules apply only to diagnostic
    | row capture; bulk exports preserve selected tables exactly.
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
