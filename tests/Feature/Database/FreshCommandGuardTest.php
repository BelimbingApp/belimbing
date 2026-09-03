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

test('migrate:fresh says so when the drop it delegates will be refused', function (): void {
    // A persistent sqlite file stands in for the real case, a migrated
    // PostgreSQL database: db:wipe permits only sqlite :memory:, so the drop
    // is refused, and the refusal is invisible -- callSilent swallows the text
    // and Task::render() matches a boolean against an int-backed enum, so the
    // line prints DONE whatever happened. See BelimbingApp/belimbing#525.
    [$connection, $database] = freshGuardConnection('fresh_guard_warns');

    try {
        // A migration repository is what makes Laravel attempt the drop at all.
        $this->artisan('migrate:install', ['--database' => $connection])->assertExitCode(0);

        $this->artisan('migrate:fresh', [
            '--database' => $connection,
            '--path' => 'database/migrations/does-not-exist',
            '--force' => true,
        ])
            ->expectsOutputToContain('The drop below will be refused')
            // Exit code 0 on purpose: both PostgreSQL CI lanes migrate first
            // and depend on this degrading to a plain migrate, and
            // tests/AGENTS.md states that as a rule. Refusing here fails 125
            // tests on one lane and 7 on the other. Say it, do not stop it.
            ->assertExitCode(0);
    } finally {
        DB::purge($connection);
        @unlink($database);
    }
});

test('migrate:fresh stays quiet when there is nothing to drop', function (): void {
    // No migration repository means Laravel never calls db:wipe, so there is
    // no refusal to report and nothing misleading to warn about.
    [$connection, $database] = freshGuardConnection('fresh_guard_quiet');

    try {
        $this->artisan('migrate:fresh', [
            '--database' => $connection,
            '--path' => 'database/migrations/does-not-exist',
            '--force' => true,
        ])
            ->doesntExpectOutputToContain('The drop below will be refused')
            ->assertExitCode(0);
    } finally {
        DB::purge($connection);
        @unlink($database);
    }
});
