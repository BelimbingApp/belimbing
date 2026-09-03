<?php

use Illuminate\Support\Facades\DB;

/**
 * @return array{string, string} [connection name, database file]
 */
function freshGuardConnection(string $name): array
{
    $database = tempnam(sys_get_temp_dir(), 'blb-fresh-guard-');

    config([
        "database.connections.$name" => [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge($name);

    return [$name, $database];
}

test('migrate:fresh refuses to delegate a drop that db:wipe will silently refuse', function (): void {
    // A persistent sqlite file stands in for the real case, a reused
    // PostgreSQL database: db:wipe permits only sqlite :memory:, so the drop
    // is refused, and the refusal cannot be seen from inside migrate:fresh --
    // callSilent swallows the text and Task::render() matches a boolean
    // against an int-backed enum, falling through to DONE. See
    // BelimbingApp/belimbing#525.
    [$connection, $database] = freshGuardConnection('fresh_guard_blocked');

    try {
        // A migration repository is what makes Laravel attempt the drop at all.
        $this->artisan('migrate:install', ['--database' => $connection])->assertExitCode(0);

        $this->artisan('migrate:fresh', ['--database' => $connection, '--force' => true])
            ->expectsOutputToContain('migrate:fresh would drop nothing here')
            ->assertExitCode(1);
    } finally {
        DB::purge($connection);
        @unlink($database);
    }
});

test('migrate:fresh is untouched when there is nothing to drop', function (): void {
    // The guard must stay out of the way of the case CI is always in: a new
    // database with no migration repository, where Laravel never calls
    // db:wipe and migrate:fresh is just migrate. Blocking here would break
    // every RefreshDatabase test on PostgreSQL for no benefit.
    [$connection, $database] = freshGuardConnection('fresh_guard_allowed');

    try {
        $this->artisan('migrate:fresh', [
            '--database' => $connection,
            '--path' => 'database/migrations/does-not-exist',
            '--force' => true,
        ])
            ->doesntExpectOutputToContain('migrate:fresh would drop nothing here')
            ->assertExitCode(0);
    } finally {
        DB::purge($connection);
        @unlink($database);
    }
});
