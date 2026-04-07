<?php

use App\Modules\Core\AI\Models\OperationDispatch;

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [

    /*
    |--------------------------------------------------------------------------
    | Action Log Retention
    |--------------------------------------------------------------------------
    |
    | Number of days to keep action log entries before pruning.
    | Mutation logs are kept forever and are not subject to pruning.
    |
    */
    'action_retention_days' => 90,

    /*
    |--------------------------------------------------------------------------
    | Event Logging Toggles
    |--------------------------------------------------------------------------
    |
    | Master switches for each category of automatic action logging.
    | Disable any category to stop buffering those events entirely.
    |
    */
    'log_http_requests' => true,
    'log_auth_events' => true,
    'log_console_commands' => true,
    'log_queue_jobs' => true,

    /*
    |--------------------------------------------------------------------------
    | Excluded Console Commands
    |--------------------------------------------------------------------------
    |
    | Commands that are too noisy to audit. These are skipped by the
    | CommandListener regardless of the log_console_commands toggle.
    |
    */
    'exclude_commands' => [
        'schedule:run',
        'schedule:work',
        'queue:work',
        'queue:listen',
        'queue:restart',
    ],

    /*
    |--------------------------------------------------------------------------
    | Globally Excluded Fields
    |--------------------------------------------------------------------------
    |
    | Fields that are never worth auditing. Stripped from mutation diffs
    | for ALL models globally.
    |
    */
    'exclude_fields' => [
        'created_at',
        'updated_at',
    ],

    /*
    |--------------------------------------------------------------------------
    | Globally Excluded Models
    |--------------------------------------------------------------------------
    |
    | Models that should never be audited. The global mutation listener
    | skips these models entirely.
    |
    */
    'exclude_models' => [
        OperationDispatch::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Globally Redacted Fields
    |--------------------------------------------------------------------------
    |
    | Fields whose values are replaced with '[redacted]' in mutation diffs.
    | The change is recorded but the actual value is never stored.
    |
    */
    'redact' => [
        'password',
        'remember_token',
        'secret',
        'api_key',
        'token',
        'two_factor_secret',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Truncation Length
    |--------------------------------------------------------------------------
    |
    | Safety net for text fields not explicitly configured. Any string value
    | exceeding this length is truncated with a '[truncated, N chars]' marker.
    |
    */
    'truncate_default' => 2000,

];
