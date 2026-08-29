<?php

use App\Base\Schedule\Services\ScheduleConfigurationGate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A default-save that began without an override is an assertion that no row
 * exists *at commit time*. This uses two independent PostgreSQL connections
 * to prove a competing override create cannot slip between that assertion and
 * the success notification (#422, kiat-luna P2).
 */
uses(TestCase::class);

const SCHEDULE_CONFIGURATION_GATE_TEST_KEY = 'zz_schedule_configuration_gate_it_key';

beforeEach(function (): void {
    if (! scheduleConfigurationGateTestsEnabled()) {
        $this->markTestSkipped('Requires a real PostgreSQL connection (DB_CONNECTION=pgsql) — see the postgres-mirror CI job or a local pgsql .env.');
    }

    config([
        'app.env' => 'testing',
        'database.default' => 'pgsql',
        'database.connections.schedule_configuration_gate_concurrent_b' => scheduleConfigurationGateSecondConnectionConfig(),
    ]);

    DB::purge('pgsql');
    DB::purge('schedule_configuration_gate_concurrent_b');

    DB::connection()->table('base_schedule_configuration_locks')->where('key', SCHEDULE_CONFIGURATION_GATE_TEST_KEY)->delete();
    DB::connection()->table('base_schedule_overrides')->where('key', SCHEDULE_CONFIGURATION_GATE_TEST_KEY)->delete();
});

afterEach(function (): void {
    if (! scheduleConfigurationGateTestsEnabled()) {
        return;
    }

    $second = DB::connection('schedule_configuration_gate_concurrent_b');

    if ($second->transactionLevel() > 0) {
        $second->rollBack();
    }

    DB::connection()->table('base_schedule_configuration_locks')->where('key', SCHEDULE_CONFIGURATION_GATE_TEST_KEY)->delete();
    DB::connection()->table('base_schedule_overrides')->where('key', SCHEDULE_CONFIGURATION_GATE_TEST_KEY)->delete();
    DB::purge('schedule_configuration_gate_concurrent_b');
});

it('does not let an empty-version assertion succeed while a concurrent override create owns the key', function (): void {
    $gate = app(ScheduleConfigurationGate::class);
    $b = DB::connection('schedule_configuration_gate_concurrent_b');

    // Seed the permanent gate first. The test deliberately exercises the
    // steady-state SELECT ... FOR UPDATE, not only first-insert contention.
    DB::connection()->table('base_schedule_configuration_locks')->insert([
        'source' => 'scheduler',
        'key' => SCHEDULE_CONFIGURATION_GATE_TEST_KEY,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Connection B represents an editor that has started the competing
    // create, but has not committed it yet. It owns the same per-key gate.
    $b->beginTransaction();
    $b->table('base_schedule_configuration_locks')
        ->where('source', 'scheduler')
        ->where('key', SCHEDULE_CONFIGURATION_GATE_TEST_KEY)
        ->lockForUpdate()
        ->first();
    $b->table('base_schedule_overrides')->insert([
        'source' => 'scheduler',
        'key' => SCHEDULE_CONFIGURATION_GATE_TEST_KEY,
        'name' => 'Concurrent override',
        'expression' => '5 5 * * *',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // The empty-version branch must not be able to observe absence and report
    // success here. Without the gate's lockForUpdate(), it would enter this
    // callback before B commits; the bounded PostgreSQL lock wait makes that
    // regression fail rather than hang this integration proof.
    expect(fn () => $gate->synchronize(SCHEDULE_CONFIGURATION_GATE_TEST_KEY, fn (): bool => true))
        ->toThrow(QueryException::class);

    $b->commit();

    // Once the competing create commits and releases the gate, a real
    // no-override assertion sees the new row and must refuse the reset.
    $absent = $gate->synchronize(
        SCHEDULE_CONFIGURATION_GATE_TEST_KEY,
        fn (): bool => ! DB::table('base_schedule_overrides')
            ->where('source', 'scheduler')
            ->where('key', SCHEDULE_CONFIGURATION_GATE_TEST_KEY)
            ->exists(),
    );

    expect($absent)->toBeFalse();
});

function scheduleConfigurationGateTestsEnabled(): bool
{
    return (string) env('DB_CONNECTION') === 'pgsql';
}

/** @return array<string, mixed> */
function scheduleConfigurationGateSecondConnectionConfig(): array
{
    return array_merge(config('database.connections.pgsql'), [
        'name' => 'schedule_configuration_gate_concurrent_b',
    ]);
}
