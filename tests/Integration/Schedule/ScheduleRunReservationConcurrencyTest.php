<?php

use App\Base\Schedule\Models\ScheduleRun;
use App\Base\Schedule\Services\ScheduleRunRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ScheduleRunRecorder::reserveManualRun() puts the active-row check, any
 * stale/supersede reconciliation, and the queued-row insert inside one
 * transaction locked on a per-key gate row — precisely so that two
 * genuinely independent database connections deciding the same key at once
 * cannot both observe "nothing active" and both insert (#407 review,
 * luna's P1 / terra's implementation guidance).
 *
 * This drives that guarantee with two real, independent PostgreSQL
 * connections in the same process — not mocks, not two calls on one
 * connection — mirroring the existing bounded-lock-wait pattern in
 * tests/Integration/Database/DevelopmentTableMirrorPostgresTest.php
 * ('serializes target mutations with a bounded advisory-lock wait'): one
 * connection holds the gate row locked via a manually-managed transaction,
 * the operation under test is proven unable to proceed while it's held
 * (bounded by reserveManualRun()'s own `lock_timeout`, so a genuine defect
 * fails the test instead of hanging it), and unblocking a moment later
 * lets it complete normally. What the database enforces between two PDO
 * connections is the same guarantee regardless of whether those
 * connections happen to live in one OS process or two.
 */
uses(TestCase::class);

const SCHEDULE_GATE_TEST_KEY = 'zz_schedule_reservation_it_key';

beforeEach(function (): void {
    if (! scheduleGateTestsEnabled()) {
        $this->markTestSkipped('Requires a real PostgreSQL connection (DB_CONNECTION=pgsql) — see the postgres-mirror CI job or a local pgsql .env.');
    }

    config([
        'app.env' => 'testing',
        'database.default' => 'pgsql',
        'database.connections.schedule_gate_concurrent_b' => scheduleGateSecondConnectionConfig(),
    ]);

    DB::purge('pgsql');
    DB::purge('schedule_gate_concurrent_b');

    DB::connection()->table('base_schedule_run_gates')->where('key', SCHEDULE_GATE_TEST_KEY)->delete();
    ScheduleRun::query()->where('key', SCHEDULE_GATE_TEST_KEY)->delete();
});

afterEach(function (): void {
    if (! scheduleGateTestsEnabled()) {
        return;
    }

    DB::connection('schedule_gate_concurrent_b')->rollBack();
    DB::connection()->table('base_schedule_run_gates')->where('key', SCHEDULE_GATE_TEST_KEY)->delete();
    ScheduleRun::query()->where('key', SCHEDULE_GATE_TEST_KEY)->delete();
    DB::purge('schedule_gate_concurrent_b');
});

it('cannot let a second connection observe "nothing active" while the gate is held', function (): void {
    $recorder = app(ScheduleRunRecorder::class);
    $b = DB::connection('schedule_gate_concurrent_b');

    // The gate row already exists and is committed — the realistic case,
    // since after a task's very first "Run now" ever, its gate row is
    // permanent. This exercises the ongoing lockForUpdate() SELECT that
    // every later click serializes on, not just insertOrIgnore()'s own
    // first-insert conflict wait, which a prior draft of this test
    // accidentally relied on instead: removing the SELECT's lockForUpdate()
    // left this version of the test still passing, because with no
    // pre-existing row the two connections' competing INSERTs were the
    // only thing blocking each other. Seeding a committed row here closes
    // that gap and was verified against the unguarded code the same way —
    // reverting lockForUpdate() on the steady-state SELECT below turns this
    // red.
    DB::connection()->table('base_schedule_run_gates')->insert([
        'source' => 'scheduler',
        'key' => SCHEDULE_GATE_TEST_KEY,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Connection B reaches the critical section first and holds it open —
    // simulating a request that is mid-decision for this exact key.
    $b->beginTransaction();
    $b->table('base_schedule_run_gates')->where('key', SCHEDULE_GATE_TEST_KEY)->lockForUpdate()->first();

    // A genuinely separate connection's reservation attempt for the same
    // key must not sail past the held lock — it blocks, and
    // reserveManualRun()'s own bounded lock_timeout turns that into an
    // exception rather than a hang, which is the proof: without the gate,
    // this call would have raced straight through to its own read.
    $startedAt = hrtime(true);

    expect(fn () => $recorder->reserveManualRun(SCHEDULE_GATE_TEST_KEY, 'inspire', null, 1, 'Ops Operator', false))
        ->toThrow(QueryException::class);

    $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
    expect($elapsedSeconds)->toBeGreaterThan(1.0)->toBeLessThan(15.0);

    // Nothing was created while blocked — the attempt that couldn't get
    // the lock made no partial decision.
    expect(ScheduleRun::query()->where('key', SCHEDULE_GATE_TEST_KEY)->count())->toBe(0);

    $b->rollBack();

    // Once B releases, an ordinary reservation for the same key succeeds
    // normally — this was contention, not a broken key.
    $reservation = $recorder->reserveManualRun(SCHEDULE_GATE_TEST_KEY, 'inspire', null, 1, 'Ops Operator', false);

    expect($reservation->created)->toBeTrue();
    expect(ScheduleRun::query()->where('key', SCHEDULE_GATE_TEST_KEY)->count())->toBe(1);
});

it('cannot let a Force supersede race ahead of a competing decision for the same key', function (): void {
    $recorder = app(ScheduleRunRecorder::class);
    $b = DB::connection('schedule_gate_concurrent_b');

    // Seed a stale active row a Force request would be entitled to supersede.
    $stale = ScheduleRun::query()->create([
        'source' => 'scheduler',
        'trigger' => 'manual',
        'key' => SCHEDULE_GATE_TEST_KEY,
        'name' => 'inspire',
        'status' => 'running',
        'started_at' => now()->subMinutes(ScheduleRunRecorder::QUEUE_PICKUP_STALE_AFTER_MINUTES + 1),
    ]);

    // A committed gate row already exists (the realistic steady-state
    // case — see the sibling test's comment for why this matters).
    DB::connection()->table('base_schedule_run_gates')->insert([
        'source' => 'scheduler',
        'key' => SCHEDULE_GATE_TEST_KEY,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Connection B holds the gate first, as if it were mid-decision for
    // this same key (an ordinary competing "Run now", not itself Forcing).
    $b->beginTransaction();
    $b->table('base_schedule_run_gates')->where('key', SCHEDULE_GATE_TEST_KEY)->lockForUpdate()->first();

    // The Force request on a different connection cannot get ahead of B —
    // it blocks on the same gate, times out rather than superseding early,
    // and the stale row it would have targeted is untouched.
    expect(fn () => $recorder->reserveManualRun(SCHEDULE_GATE_TEST_KEY, 'inspire', null, 2, 'Force Operator', true))
        ->toThrow(QueryException::class);

    $stale->refresh();
    expect($stale->status)->toBe('running')
        ->and($stale->finished_at)->toBeNull();

    $b->rollBack();

    // Once unblocked, Force supersedes exactly the row that was actually
    // stale — no fresh competing row exists to be superseded by mistake.
    $reservation = $recorder->reserveManualRun(SCHEDULE_GATE_TEST_KEY, 'inspire', null, 2, 'Force Operator', true);

    expect($reservation->created)->toBeTrue();

    $stale->refresh();
    expect($stale->status)->toBe('failed')
        ->and($stale->output_excerpt)->toContain('Superseded by a new manual run requested by Force Operator');

    expect(ScheduleRun::query()->where('key', SCHEDULE_GATE_TEST_KEY)->where('status', 'queued')->count())->toBe(1);
});

function scheduleGateTestsEnabled(): bool
{
    return (string) env('DB_CONNECTION') === 'pgsql';
}

/** @return array<string, mixed> */
function scheduleGateSecondConnectionConfig(): array
{
    // A second, independent Laravel connection (its own PDO handle) to the
    // exact same database the default 'pgsql' connection uses — not a
    // second database, which would defeat the point of testing row-lock
    // contention on a shared table.
    return array_merge(config('database.connections.pgsql'), [
        'name' => 'schedule_gate_concurrent_b',
    ]);
}
