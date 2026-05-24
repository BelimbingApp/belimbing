<?php

use Illuminate\Support\Facades\DB;

test('db wipe is blocked for persistent databases', function (): void {
    $database = tempnam(sys_get_temp_dir(), 'blb-wipe-guard-');

    config([
        'database.connections.wipe_guard_test' => [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge('wipe_guard_test');

    try {
        $this->artisan('db:wipe', [
            '--database' => 'wipe_guard_test',
            '--force' => true,
        ])
            ->expectsOutputToContain('db:wipe is blocked because it bypasses BLB incubating-schema safeguards.')
            ->assertExitCode(1);
    } finally {
        DB::purge('wipe_guard_test');
        @unlink($database);
    }
});
