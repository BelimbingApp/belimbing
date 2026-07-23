<?php

use App\Base\Database\Enums\DataFreshnessState;
use App\Base\Database\Services\DataShare\Freshness\DataFreshnessTracker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Phase 3 proof-gate: freshness triggers on a disposable PostgreSQL database.
 *
 * This is the integration evidence the plan requires — it runs only against a
 * real PostgreSQL default connection (set BLB_POSTGRES_FRESHNESS_TESTS=true) and
 * is skipped on SQLite, matching the existing mirror integration tests. It
 * proves the statement-level trigger observes every writer, rolls back with the
 * source transaction, and covers TRUNCATE.
 */
const FRESHNESS_PROBE_TABLE = 'zz_freshness_probe';

function freshnessPostgresTestsEnabled(): bool
{
    return filter_var(env('BLB_POSTGRES_FRESHNESS_TESTS', false), FILTER_VALIDATE_BOOL);
}

beforeEach(function (): void {
    if (! freshnessPostgresTestsEnabled()) {
        $this->markTestSkipped('Set BLB_POSTGRES_FRESHNESS_TESTS=true and provide an isolated PostgreSQL database.');
    }

    config([
        'database.default' => 'pgsql',
        'database.connections.pgsql' => array_merge((array) config('database.connections.pgsql'), [
            'driver' => 'pgsql',
            'host' => env('BLB_FRESHNESS_PG_HOST', '127.0.0.1'),
            'port' => (int) env('BLB_FRESHNESS_PG_PORT', 5435),
            'database' => env('BLB_FRESHNESS_PG_DB', 'blb_freshness_local'),
            'username' => env('BLB_FRESHNESS_PG_USER', 'postgres'),
            'password' => (string) env('BLB_FRESHNESS_PG_PASS', ''),
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]),
    ]);
    DB::purge('pgsql');

    $connection = DB::connection();
    $connection->statement('DROP TABLE IF EXISTS '.FRESHNESS_PROBE_TABLE.', zz_freshness_probe_b CASCADE');
    $connection->statement('CREATE TABLE '.FRESHNESS_PROBE_TABLE.' (id serial PRIMARY KEY, v integer)');
    $connection->statement('CREATE TABLE zz_freshness_probe_b (id serial PRIMARY KEY, v integer)');

    if (! Schema::hasTable('base_database_data_freshness_events')) {
        $connection->statement(<<<'SQL'
            CREATE TABLE base_database_data_freshness_events (
                id bigserial PRIMARY KEY,
                table_name varchar(255) NOT NULL,
                occurred_at timestamptz NOT NULL DEFAULT now()
            )
            SQL);
        $connection->statement('CREATE INDEX ON base_database_data_freshness_events (table_name, id)');
    } else {
        $connection->table('base_database_data_freshness_events')
            ->whereIn('table_name', [FRESHNESS_PROBE_TABLE, 'zz_freshness_probe_b'])->delete();
    }

    app(DataFreshnessTracker::class)->installTracking(FRESHNESS_PROBE_TABLE);
    app(DataFreshnessTracker::class)->installTracking('zz_freshness_probe_b');
});

afterEach(function (): void {
    if (! freshnessPostgresTestsEnabled()) {
        return;
    }

    DB::connection()->statement('DROP TABLE IF EXISTS '.FRESHNESS_PROBE_TABLE.', zz_freshness_probe_b CASCADE');
    DB::connection()->table('base_database_data_freshness_events')
        ->whereIn('table_name', [FRESHNESS_PROBE_TABLE, 'zz_freshness_probe_b'])->delete();
});

it('bumps the generation on every statement, honors rollback, and covers TRUNCATE', function (): void {
    $tracker = app(DataFreshnessTracker::class);
    $connection = DB::connection();

    // Installing the trigger writes no generation row; DDL does not fire it.
    expect($tracker->currentGeneration(FRESHNESS_PROBE_TABLE))->toBeNull();

    $connection->table(FRESHNESS_PROBE_TABLE)->insert(['v' => 1]);
    $afterInsert = $tracker->currentGeneration(FRESHNESS_PROBE_TABLE);
    expect($afterInsert)->toBeGreaterThan(0);

    $connection->table(FRESHNESS_PROBE_TABLE)->update(['v' => 2]);
    $afterUpdate = $tracker->currentGeneration(FRESHNESS_PROBE_TABLE);
    expect($afterUpdate)->toBeGreaterThan($afterInsert);

    // A rolled-back write must not mark the table changed.
    $connection->beginTransaction();
    $connection->table(FRESHNESS_PROBE_TABLE)->insert(['v' => 3]);
    $connection->rollBack();
    expect($tracker->currentGeneration(FRESHNESS_PROBE_TABLE))->toBe($afterUpdate);

    // TRUNCATE is covered by the statement-level trigger.
    $connection->statement('TRUNCATE '.FRESHNESS_PROBE_TABLE);
    $afterTruncate = $tracker->currentGeneration(FRESHNESS_PROBE_TABLE);
    expect($afterTruncate)->toBeGreaterThan($afterUpdate);

    // DELETE also bumps the generation.
    $connection->table(FRESHNESS_PROBE_TABLE)->insert(['v' => 4]);
    $beforeDelete = $tracker->currentGeneration(FRESHNESS_PROBE_TABLE);
    $connection->table(FRESHNESS_PROBE_TABLE)->delete();
    expect($tracker->currentGeneration(FRESHNESS_PROBE_TABLE))->toBeGreaterThan($beforeDelete);
});

it('captures and acknowledges exactly the generation used by a push', function (): void {
    $tracker = app(DataFreshnessTracker::class);
    $connection = DB::connection();

    $connection->table(FRESHNESS_PROBE_TABLE)->insert(['v' => 1]);
    $captured = $tracker->currentGeneration(FRESHNESS_PROBE_TABLE);

    // Clean immediately after acknowledging the captured generation.
    expect($tracker->state(FRESHNESS_PROBE_TABLE, $captured))->toBe(DataFreshnessState::Clean);

    // A concurrent commit after capture stays "changed", never falsely clean.
    $connection->table(FRESHNESS_PROBE_TABLE)->insert(['v' => 2]);
    expect($tracker->state(FRESHNESS_PROBE_TABLE, $captured))->toBe(DataFreshnessState::Changed);
});
