<?php

use Illuminate\Support\Facades\DB;

function expectPersistentMigrationCommandBlocked(string $command, string $connection, string $prefix): void
{
    $database = tempnam(sys_get_temp_dir(), $prefix);

    config([
        'database.connections.'.$connection => [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge($connection);

    try {
        test()->artisan($command, [
            '--database' => $connection,
            '--force' => true,
        ])
            ->expectsOutputToContain($command.' is blocked — it bypasses the incubating-schema rebuild flow')
            ->assertExitCode(1);
    } finally {
        DB::purge($connection);
        @unlink($database);
    }
}

test('migrate:refresh is blocked for persistent databases', function (): void {
    expectPersistentMigrationCommandBlocked('migrate:refresh', 'refresh_guard_test', 'blb-refresh-guard-');
});

test('migrate:reset is blocked for persistent databases', function (): void {
    expectPersistentMigrationCommandBlocked('migrate:reset', 'reset_guard_test', 'blb-reset-guard-');
});
