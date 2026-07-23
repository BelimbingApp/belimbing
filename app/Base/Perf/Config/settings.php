<?php

use App\Base\Perf\Services\PerfRuntimeSettings;

return [
    'definitions' => [
        PerfRuntimeSettings::ENABLED_KEY => [
            'type' => 'boolean',
            'scopes' => ['global'],
            'default' => true,
            'nullable' => false,
            'encrypted' => false,
            'rules' => ['required', 'boolean'],
            'label' => 'Record performance activity',
            'help' => 'Record HTTP requests, queue jobs, and console commands. Shipped default: :default.',
        ],
        PerfRuntimeSettings::MINIMUM_DURATION_MS_KEY => [
            'type' => 'float',
            'scopes' => ['global'],
            'default' => 0.0,
            'nullable' => false,
            'encrypted' => false,
            'rules' => ['required', 'numeric', 'min:0', 'max:3600000'],
            'label' => 'Minimum duration',
            'help' => 'Skip work faster than this threshold. Use 0 to retain the complete distribution. Shipped default: :default ms.',
        ],
        PerfRuntimeSettings::SLOW_SQL_MINIMUM_DURATION_MS_KEY => [
            'type' => 'float',
            'scopes' => ['global'],
            'default' => 20.0,
            'nullable' => false,
            'encrypted' => false,
            'rules' => ['required', 'numeric', 'min:0', 'max:3600000'],
            'label' => 'Slow SQL threshold',
            'help' => 'Keep up to three SQL statements at or above this duration in each entry. Shipped default: :default ms.',
        ],
        PerfRuntimeSettings::LOG_PATH_KEY => [
            'type' => 'string',
            'scopes' => ['global'],
            'default' => null,
            'nullable' => true,
            'encrypted' => false,
            'rules' => ['nullable', 'string', 'max:2048'],
            'label' => 'Log directory',
            'help' => 'Absolute directory for perf-*.jsonl files. Leave blank to use storage/logs on the compatible runtime host.',
        ],
        PerfRuntimeSettings::RETENTION_DAYS_KEY => [
            'type' => 'integer',
            'scopes' => ['global'],
            'default' => 14,
            'nullable' => false,
            'encrypted' => false,
            'rules' => ['required', 'integer', 'min:1', 'max:3650'],
            'label' => 'Retention',
            'help' => 'Daily log files older than this are removed by perf:prune. Shipped default: :default days.',
        ],
    ],
];
