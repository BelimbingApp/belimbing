<?php

use Illuminate\Support\Facades\DB;

test('migrate:refresh is blocked for persistent databases', function (): void {
    $database = tempnam(sys_get_temp_dir(), 'blb-refresh-guard-');

    config([
        'database.connections.refresh_guard_test' => [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge('refresh_guard_test');

    try {
        $this->artisan('migrate:refresh', [
            '--database' => 'refresh_guard_test',
            '--force' => true,
        ])
            ->expectsOutputToContain('migrate:refresh is blocked — it bypasses the incubating-schema rebuild flow')
            ->assertExitCode(1);
    } finally {
        DB::purge('refresh_guard_test');
        @unlink($database);
    }
});

test('migrate:reset is blocked for persistent databases', function (): void {
    $database = tempnam(sys_get_temp_dir(), 'blb-reset-guard-');

    config([
        'database.connections.reset_guard_test' => [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge('reset_guard_test');

    try {
        $this->artisan('migrate:reset', [
            '--database' => 'reset_guard_test',
            '--force' => true,
        ])
            ->expectsOutputToContain('migrate:reset is blocked — it bypasses the incubating-schema rebuild flow')
            ->assertExitCode(1);
    } finally {
        DB::purge('reset_guard_test');
        @unlink($database);
    }
});
