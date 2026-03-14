<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use Illuminate\Support\Facades\Log;

if (! function_exists('blb_log_var')) {
    /**
     * Write arbitrary data into a dedicated file under storage/logs.
     *
     * @param  mixed  $value  Value to serialize and write
     * @param  string  $file  Target log filename (with or without .log extension)
     * @param  array<string, mixed>  $context  Optional structured log context
     * @param  string  $level  PSR log level (e.g. info, debug, warning, error)
     */
    function blb_log_var(
        mixed $value,
        string $file = 'debug.log',
        array $context = [],
        string $level = 'info',
    ): void
    {
        $filename = basename(trim($file));

        if ($filename === '') {
            $filename = 'debug.log';
        }

        if (! str_ends_with($filename, '.log')) {
            $filename .= '.log';
        }

        $message = is_string($value) ? $value : var_export($value, true);

        static $channels = [];
        $channels[$filename] ??= Log::build([
            'driver' => 'single',
            'path' => storage_path('logs/'.$filename),
        ]);

        $channels[$filename]->log($level, $message, $context);
    }
}
