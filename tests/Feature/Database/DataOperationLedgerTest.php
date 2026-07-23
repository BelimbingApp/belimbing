<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorCatalogTable;
use App\Base\Database\Enums\DataFreshnessState;
use App\Base\Database\Enums\DataOperationRangeKind;
use App\Base\Database\Enums\DataOperationStatus;
use App\Base\Database\Enums\DataOperationType;
use App\Base\Database\Models\DataOperationRun;
use App\Base\Database\Models\DataOperationTableSummary;
use App\Base\Database\Models\DataShareMirrorObservation;
use App\Base\Database\Services\DataOperation\DataOperationReconciler;
use App\Base\Database\Services\DataOperation\LedgerDataOperationRecorder;
use App\Base\Database\Services\DataShare\Freshness\DataFreshnessAttachmentService;
use App\Base\Database\Services\DataShare\Freshness\DataFreshnessTracker;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorCatalog;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorObservationProjection;
use App\Base\Foundation\Contracts\DataOperationRecorder;
use App\Base\Foundation\Contracts\SemanticActionRecorder;

/**
 * Counts semantic-action emissions so we can assert the audit projection is
 * attempted at most once per run (idempotent by run id).
 */
function recordingSpy(): SemanticActionRecorder
{
    return new class implements SemanticActionRecorder
    {
        /** @var list<array<string, mixed>> */
        public array $calls = [];

        public function record(
            string $event,
            string $summary,
            ?string $source = null,
            array $subject = [],
            ?string $surface = null,
            ?string $uiElement = null,
            array $context = [],
            string $result = 'succeeded',
            bool $retain = true,
        ): void {
            $this->calls[] = compact('event', 'summary', 'source', 'subject', 'context', 'result');
        }
    };
}

it('binds the real ledger recorder over the Foundation null default', function () {
    expect(app(DataOperationRecorder::class))->toBeInstanceOf(LedgerDataOperationRecorder::class);
});

it('records an import run with honest per-action effect counts and a projected action', function () {
    $spy = recordingSpy();
    app()->instance(SemanticActionRecorder::class, $spy);
    $recorder = app(DataOperationRecorder::class);

    $runId = $recorder->open(DataOperationType::AxImport->value, [
        'source' => 'sbg.ibp.market-spot',
    ]);

    expect($runId)->toBeGreaterThan(0);

    $run = DataOperationRun::query()->findOrFail($runId);
    expect($run->status)->toBe(DataOperationStatus::Running)
        ->and($run->actor_type)->toBe(PrincipalType::CONSOLE->value) // tests run in console
        ->and($run->table_count)->toBe(0);

    // The importer both upserts and prunes stale rows and rejects invalid ones.
    // Deletions must never be folded into the rejected count.
    $recorder->recordTable($runId, 'sbg_ibp_market_spot_quotes', [
        'actions' => ['upsert', 'delete'],
        'rows_written' => 1500,
        'rows_deleted' => 42,
        'rows_rejected' => 7,
        'key_columns' => ['id'],
        'range_kind' => DataOperationRangeKind::MinMaxHint->value,
        'first_key' => '100432',
        'last_key' => '100987',
    ]);

    $recorder->finalize($runId, DataOperationStatus::Succeeded->value);

    $summary = DataOperationTableSummary::query()->where('run_id', $runId)->sole();
    expect($summary->actions)->toBe(['upsert', 'delete'])
        ->and($summary->rows_deleted)->toBe(42)
        ->and($summary->rows_rejected)->toBe(7)
        ->and($summary->rows_deleted)->not->toBe($summary->rows_rejected)
        ->and($summary->range_kind)->toBe(DataOperationRangeKind::MinMaxHint)
        ->and($summary->first_key)->toBe('100432');

    $run->refresh();
    expect($run->status)->toBe(DataOperationStatus::Succeeded)
        ->and($run->table_count)->toBe(1)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and($run->total_rows_affected)->toBe(1542) // 1500 written + 42 deleted
        ->and($run->audit_projection_attempted_at)->not->toBeNull();

    expect($spy->calls)->toHaveCount(1);
    expect($spy->calls[0]['event'])->toBe('data_operation.ax_import')
        ->and($spy->calls[0]['subject']['id'])->toBe($runId)
        ->and($spy->calls[0]['subject']['name'])->toBe('data_operation');
});

it('projects the audit action at most once even when finalize is retried', function () {
    $spy = recordingSpy();
    app()->instance(SemanticActionRecorder::class, $spy);
    $recorder = app(DataOperationRecorder::class);

    $runId = $recorder->open(DataOperationType::MirrorPush->value, ['direction' => 'push']);
    $recorder->finalize($runId, DataOperationStatus::Succeeded->value);
    $recorder->finalize($runId, DataOperationStatus::Succeeded->value); // reconcile / retry

    expect($spy->calls)->toHaveCount(1);
});

it('refuses to resume a run that does not exist', function () {
    $recorder = app(DataOperationRecorder::class);

    expect(fn () => $recorder->resume(999999))->toThrow(RuntimeException::class);

    $runId = $recorder->open(DataOperationType::AxImport->value);
    expect(fn () => $recorder->resume($runId))->not->toThrow(RuntimeException::class);
});

it('permanently protects the ledger tables from mirroring', function () {
    $protected = (new ReflectionClass(DataShareMirrorCatalog::class))
        ->getConstant('FIXED_PROTECTED_TABLES');

    expect($protected)->toContain('base_database_data_operation_runs')
        ->and($protected)->toContain('base_database_data_operation_tables')
        ->and($protected)->toContain('base_database_data_share_observations');
});

it('keeps only the latest observation per endpoint pair and table so it survives refresh', function () {
    $projection = app(DataShareMirrorObservationProjection::class);

    $projection->record('local-1', 'remote-1', 'sbg_products', 10, 84120, 84120);
    $projection->record('local-1', 'remote-1', 'sbg_products', 11, 84200, 84190);

    $rows = DataShareMirrorObservation::query()->where('table_name', 'sbg_products')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->local_rows)->toBe(84200)
        ->and($rows->first()->remote_rows)->toBe(84190)
        ->and($rows->first()->run_id)->toBe(11);
});

it('isolates observations by endpoint so a different mirror never overwrites another', function () {
    $projection = app(DataShareMirrorObservationProjection::class);

    $projection->record('local-1', 'remote-a', 'sbg_products', 1, 100, 100);
    $projection->record('local-1', 'remote-b', 'sbg_products', 2, 200, 200);

    expect(DataShareMirrorObservation::query()->where('table_name', 'sbg_products')->count())->toBe(2);
});

it('merges persisted observations into catalog rows, leaving unobserved rows untouched', function () {
    app(DataShareMirrorObservationProjection::class)
        ->record('local-1', 'remote-1', 'sbg_products', 5, 84120, 84000);

    $observed = new DataShareMirrorCatalogTable(
        table: 'sbg_products', moduleName: null, modulePath: null, migrationFile: null,
        localExists: true, mirrorExists: true, localKind: 'table', mirrorKind: 'table', supported: true,
    );
    $unobserved = new DataShareMirrorCatalogTable(
        table: 'sbg_orders', moduleName: null, modulePath: null, migrationFile: null,
        localExists: true, mirrorExists: false, localKind: 'table', mirrorKind: null, supported: true,
    );

    $merged = app(DataShareMirrorCatalog::class)
        ->mergeObservations([$observed, $unobserved], 'local-1', 'remote-1');

    expect($merged[0]->localRows)->toBe(84120)
        ->and($merged[0]->remoteRows)->toBe(84000)
        ->and($merged[0]->observedAt)->not->toBeNull()
        ->and($merged[1]->localRows)->toBeNull()
        ->and($merged[1]->observedAt)->toBeNull();
});

it('reports freshness as Unknown on SQLite, never Clean', function () {
    $tracker = app(DataFreshnessTracker::class);

    // The test runner is SQLite: tracking is unsupported and nothing is installed.
    expect($tracker->driverSupportsTracking())->toBeFalse()
        ->and($tracker->state('sbg_products', null))->toBe(DataFreshnessState::Unknown)
        ->and($tracker->state('sbg_products', 5))->toBe(DataFreshnessState::Unknown);
});

it('reads the generation as the latest append-only event and compacts to one per table', function () {
    // Generation = MAX(id) of a table's events; compaction keeps only the latest.
    DB::table('base_database_data_freshness_events')->insert([
        ['table_name' => 'sbg_widgets', 'occurred_at' => now()],
        ['table_name' => 'sbg_widgets', 'occurred_at' => now()],
        ['table_name' => 'sbg_orders', 'occurred_at' => now()],
    ]);

    $tracker = app(DataFreshnessTracker::class);

    expect($tracker->currentGeneration('sbg_widgets'))->toBe(2)
        ->and($tracker->currentGeneration('never_tracked'))->toBeNull();

    $tracker->compact();

    // One latest event kept per table; the generation (MAX id) is unchanged.
    expect(DB::table('base_database_data_freshness_events')->count())->toBe(2)
        ->and($tracker->currentGeneration('sbg_widgets'))->toBe(2);
});

it('builds an append-only statement-level PostgreSQL trigger that covers TRUNCATE', function () {
    $tracker = app(DataFreshnessTracker::class);

    expect($tracker->functionSql())
        ->toContain('base_database_data_freshness_touch')
        ->toContain('INSERT INTO base_database_data_freshness_events')
        ->toContain('TG_TABLE_NAME')
        ->not->toContain('ON CONFLICT'); // append-only: no shared row to conflict on

    expect($tracker->triggerSql('sbg_products'))
        ->toContain('FOR EACH STATEMENT')
        ->toContain('AFTER INSERT OR UPDATE OR DELETE OR TRUNCATE')
        ->toContain('blb_freshness_touch');
});

it('protects the freshness events table from mirroring', function () {
    $protected = (new ReflectionClass(DataShareMirrorCatalog::class))->getConstant('FIXED_PROTECTED_TABLES');

    expect($protected)->toContain('base_database_data_freshness_events');
});

it('renders every catalog row with Unknown freshness on SQLite', function () {
    app(DataShareMirrorObservationProjection::class)->record('local-1', 'remote-1', 'sbg_products', 5, 10, 10);

    $row = new DataShareMirrorCatalogTable(
        table: 'sbg_products', moduleName: null, modulePath: null, migrationFile: null,
        localExists: true, mirrorExists: true, localKind: 'table', mirrorKind: 'table', supported: true,
    );

    $merged = app(DataShareMirrorCatalog::class)->mergeObservations([$row], 'local-1', 'remote-1');

    expect($merged[0]->freshness)->toBe('unknown')
        ->and($merged[0]->localRows)->toBe(10);
});

it('installs no tracking and stays a safe no-op on an unsupported driver', function () {
    $tracker = app(DataFreshnessTracker::class);

    $tracker->installTracking('sbg_products'); // SQLite: must not throw or install anything

    expect($tracker->currentGeneration('sbg_products'))->toBeNull();
});

it('acknowledges a captured push generation and leaves it untouched on later non-tracking observations', function () {
    $projection = app(DataShareMirrorObservationProjection::class);

    $projection->record('local-1', 'remote-1', 'sbg_products', 1, 10, 10, 42); // push acknowledges gen 42
    $observation = DataShareMirrorObservation::query()->where('table_name', 'sbg_products')->firstOrFail();
    expect($observation->acknowledged_generation)->toBe(42);

    $projection->record('local-1', 'remote-1', 'sbg_products', 2, 11, 11, null); // baseline: no ack
    expect($observation->refresh()->acknowledged_generation)->toBe(42);
});

it('attaches no freshness tracking on an unsupported driver', function () {
    $result = app(DataFreshnessAttachmentService::class)->attachEligible();

    expect($result['driver_supported'])->toBeFalse()
        ->and($result['attached'])->toBe([]);
});

it('keeps ledger bookkeeping out of mutation audit while emitting one semantic action', function () {
    $spy = recordingSpy();
    app()->instance(SemanticActionRecorder::class, $spy);
    $recorder = app(DataOperationRecorder::class);

    $runId = $recorder->open('mirror_push', ['direction' => 'push']);
    $recorder->recordTable($runId, 'sbg_products', ['actions' => ['mirror'], 'rows_before' => 10, 'rows_after' => 10]);
    $recorder->finalize($runId, 'succeeded');

    // Exactly one semantic action projected for the whole operation.
    expect($spy->calls)->toHaveCount(1);

    // The ledger models are excluded from global mutation audit...
    expect(config('audit.exclude_models'))
        ->toContain(DataOperationRun::class)
        ->toContain(DataOperationTableSummary::class)
        ->toContain(DataShareMirrorObservation::class);

    // ...and no mutation rows were recorded for the operation's own bookkeeping.
    expect(DB::table('base_audit_mutations')->whereIn('auditable_type', [
        DataOperationRun::class,
        DataOperationTableSummary::class,
        DataShareMirrorObservation::class,
    ])->count())->toBe(0);
});

it('upserts table summaries and keeps counts idempotent under resume/retry', function () {
    $recorder = app(DataOperationRecorder::class);
    $runId = $recorder->open('ax_import', ['source' => 'sbg.ibp.market-spot']);

    $recorder->recordTable($runId, 'sbg_products', ['actions' => ['upsert'], 'rows_written' => 5]);
    $recorder->recordTable($runId, 'sbg_products', ['actions' => ['upsert'], 'rows_written' => 9]); // resume/retry same table

    expect(DataOperationTableSummary::query()->where('run_id', $runId)->count())->toBe(1)
        ->and(DataOperationTableSummary::query()->where('run_id', $runId)->sole()->rows_written)->toBe(9)
        ->and(DataOperationRun::query()->find($runId)->table_count)->toBe(1);
});

it('reconciles stale running operations to indeterminate, leaving recent ones running', function () {
    $recorder = app(DataOperationRecorder::class);

    $stale = $recorder->open('mirror_push', ['direction' => 'push']);
    DataOperationRun::query()->whereKey($stale)->update(['started_at' => now()->subHours(5)]);
    $recent = $recorder->open('mirror_push', ['direction' => 'push']);

    $count = app(DataOperationReconciler::class)->reconcileStale();

    expect($count)->toBe(1)
        ->and(DataOperationRun::query()->find($stale)->status->value)->toBe('indeterminate')
        ->and(DataOperationRun::query()->find($stale)->finished_at)->not->toBeNull()
        ->and(DataOperationRun::query()->find($recent)->status->value)->toBe('running');
});
