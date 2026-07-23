<?php

use App\Base\Database\Contracts\DataShareMirrorEngine;
use App\Base\Database\Contracts\DataShareMirrorProcessRunner;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorBlocker;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorCatalogTable;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorConnectionStatus;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorExecutionResult;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorReview;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorReviewItem;
use App\Base\Database\Enums\DataShareMirrorAction;
use App\Base\Database\Enums\DataShareMirrorDirection;
use App\Base\Database\Exceptions\DataShareMirrorException;
use App\Base\Database\Models\DataOperationRun;
use App\Base\Database\Models\DataShareMirrorObservation;
use App\Base\Database\Models\TableRegistry;
use App\Base\Database\Services\DataShare\DataShareInstanceIdentityResolver;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorCatalog;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorConnectionManager;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorDependencyInspector;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorEndpoint;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorEngineRegistry;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorManager;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorOperationLock;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorProviderInitializer;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorProviderRegistry;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorReviewer;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorSchemaComparator;
use App\Base\Foundation\Contracts\DataOperationRecorder;
use App\Base\Foundation\Services\NullDataOperationRecorder;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Models\Setting;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('stores the mirror URL encrypted and never includes it in safe connection status', function (): void {
    $url = 'postgresql://mirror_user:private-password@example.test:5432/belimbing?sslmode=require&application_name=private-password';
    app(SettingsService::class)->set(DataShareMirrorConnectionManager::SETTING_KEY, $url, encrypted: true);

    $row = Setting::query()->where('key', DataShareMirrorConnectionManager::SETTING_KEY)->firstOrFail();
    $status = app(DataShareMirrorManager::class)->status();
    $serialized = json_encode($status->toArray(), JSON_THROW_ON_ERROR);

    expect($row->is_encrypted)->toBeTrue()
        ->and((string) $row->getRawOriginal('value'))->not->toContain('private-password', 'example.test', 'mirror_user')
        ->and($serialized)->not->toContain($url, 'private-password', 'example.test', 'mirror_user')
        ->and($status->available)->toBeFalse();
});

it('rejects PostgreSQL URL options outside the narrow connection policy', function (string $url): void {
    $status = app(DataShareMirrorManager::class)->testConnection($url);

    expect($status->available)->toBeFalse()
        ->and($status->reasonCode)->toBe('invalid_url');
})->with([
    'nested endpoint replacement' => 'postgresql://mirror_user:secret@example.test/database?sslmode=require&url=postgresql%3A%2F%2Fattacker%3Asecret%40elsewhere.test%2Fother',
    'arbitrary libpq option' => 'postgresql://mirror_user:secret@example.test/database?sslmode=require&application_name=redirected',
    'nested allowed option' => 'postgresql://mirror_user:secret@example.test/database?sslmode%5B0%5D=require',
]);

it('reports a missing PostgreSQL driver explicitly instead of a generic connection error', function (): void {
    $manager = Mockery::mock(DataShareMirrorConnectionManager::class.'[availablePdoDrivers]', [
        app(SettingsService::class),
        app(DatabaseManager::class),
        app(DataShareInstanceIdentityResolver::class),
        app(DataShareMirrorProcessRunner::class),
        app(DataShareMirrorProviderRegistry::class),
    ])->shouldAllowMockingProtectedMethods();
    $manager->shouldReceive('availablePdoDrivers')->andReturn(['sqlite']);

    $status = $manager->testConnection('postgresql://mirror_user:secret@example.test/database?sslmode=require');

    expect($status->reachable)->toBeFalse()
        ->and($status->reasonCode)->toBe('driver_unloaded')
        ->and($status->message)->toContain('pdo_pgsql')
        ->and($status->message)->toContain('restart')
        ->and($status->message)->not->toContain('unexpected database error');
});

it('surfaces the real DNS failure instead of masking it behind a reconnect error', function (): void {
    // Regression: connectUsing() built the candidate connection without
    // registering its config. Laravel classifies "could not translate host
    // name" as a lost connection and retries via reconnect(), which then
    // threw "Database connection [data_share_mirror_candidate] not
    // configured" — replacing the actionable DNS error with the generic
    // "unexpected database error" diagnostic (reference QJ1MS4EG, 2026-07-22).
    app(SettingsService::class)->set('data_share.instance.id', 'mirror-backend-local');
    app(SettingsService::class)->set('data_share.instance.name', 'Mirror Backend Local');
    app(SettingsService::class)->set('data_share.instance.role', 'development');

    $status = app(DataShareMirrorManager::class)->testConnection(
        'postgresql://mirror_user:secret@blb-nonexistent-mirror-host.invalid:5432/postgres?sslmode=require',
    );

    expect($status->reachable)->toBeFalse()
        ->and($status->reasonCode)->toBe('connection_failed')
        ->and($status->message)->toContain('hostname could not be resolved')
        ->and($status->message)->not->toContain('unexpected database error');
})->skip(
    fn (): bool => ! in_array('pgsql', PDO::getAvailableDrivers(), true),
    'pdo_pgsql is not loaded in this test runner',
);

it('uses the already normalized PDO endpoint for PostgreSQL process tooling', function (): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getConfig')->once()->andReturn([
        'driver' => 'pgsql',
        'host' => 'verified.example.test',
        'port' => 5432,
        'database' => 'verified_database',
        'username' => 'verified_user',
        'password' => 'verified_password',
        'sslmode' => 'require',
        'connect_timeout' => 15,
        'url' => 'postgresql://attacker:secret@elsewhere.test/other',
    ]);

    $configuration = app(DataShareMirrorConnectionManager::class)->processConfiguration($connection);

    expect($configuration['host'])->toBe('verified.example.test')
        ->and($configuration['database'])->toBe('verified_database')
        ->and($configuration['username'])->toBe('verified_user')
        ->and($configuration)->not->toHaveKey('url');
});

it('registers Supabase and generic PostgreSQL as separate provider choices', function (): void {
    $registry = app(DataShareMirrorProviderRegistry::class);

    expect($registry->options())->toBe([
        'supabase' => 'Supabase',
        'postgresql' => 'PostgreSQL',
    ])->and($registry->get('supabase')->configuration(
        'postgresql://mirror_user:secret@example.test/database?sslmode=require',
    )['driver'])->toBe('pgsql');
});

it('initializes provider identity after migration without copying the local instance id', function (): void {
    $connections = Mockery::mock(DataShareMirrorConnectionManager::class);
    $connections->shouldReceive('mirrorForInitialization')->once()->andReturn(DB::connection());
    $connections->shouldReceive('provider')->once()->andReturn(app(DataShareMirrorProviderRegistry::class)->get('supabase'));
    $connections->shouldReceive('purge')->once();
    $connections->shouldReceive('status')->once()->andReturn(new DataShareMirrorConnectionStatus(
        configured: true,
        available: true,
        reachable: true,
        driver: 'pgsql',
        localRole: 'development',
        remoteRole: 'development',
        serverVersion: '17',
        pgDumpVersion: null,
        psqlVersion: null,
        reasonCode: null,
        message: 'Ready.',
    ));
    Artisan::shouldReceive('call')
        ->once()
        ->with('migrate', ['--database' => DataShareMirrorConnectionManager::CONNECTION, '--force' => true])
        ->andReturn(0);

    (new DataShareMirrorProviderInitializer($connections))->initialize();

    $remoteId = json_decode((string) DB::table('base_settings')->where('key', 'data_share.instance.id')->value('value'), true);
    $remoteRole = json_decode((string) DB::table('base_settings')->where('key', 'data_share.instance.role')->value('value'), true);
    expect($remoteId)->toStartWith('mirror-')
        ->not->toBe('local')
        ->and($remoteRole)->toBe('development');
});

it('redacts provider initialization diagnostics at the UI service boundary', function (): void {
    $connections = Mockery::mock(DataShareMirrorConnectionManager::class);
    $connections->shouldReceive('mirrorForInitialization')->once()->andReturn(DB::connection());
    Artisan::shouldReceive('call')->once()->andThrow(new RuntimeException(
        'postgresql://private-user:private-password@private-host.example/database',
    ));

    try {
        (new DataShareMirrorProviderInitializer($connections))->initialize();
        $this->fail('The unsafe initialization exception was not wrapped.');
    } catch (DataShareMirrorException $exception) {
        expect($exception->getMessage())
            ->not->toContain('private-user', 'private-password', 'private-host.example');
    }
});

it('purges the named mirror connection when no credential is configured', function (): void {
    $settings = Mockery::mock(SettingsService::class);
    $settings->shouldReceive('get')
        ->once()
        ->with(DataShareMirrorConnectionManager::PROVIDER_SETTING_KEY, 'supabase')
        ->andReturn('supabase');
    $settings->shouldReceive('get')
        ->once()
        ->with(DataShareMirrorConnectionManager::SETTING_KEY)
        ->andReturnNull();
    $database = Mockery::mock(DatabaseManager::class);
    $database->shouldReceive('purge')
        ->once()
        ->with(DataShareMirrorConnectionManager::CONNECTION);

    $connections = new DataShareMirrorConnectionManager(
        $settings,
        $database,
        Mockery::mock(DataShareInstanceIdentityResolver::class),
        Mockery::mock(DataShareMirrorProcessRunner::class),
        app(DataShareMirrorProviderRegistry::class),
    );

    expect($connections->status()->reasonCode)->toBe('not_configured');
});

it('refuses empty duplicate and malformed table selections before endpoint discovery', function (array $tables): void {
    $reviewer = new DataShareMirrorReviewer(
        Mockery::mock(DataShareMirrorConnectionManager::class),
        Mockery::mock(DataShareMirrorCatalog::class),
        Mockery::mock(DataShareMirrorDependencyInspector::class),
        Mockery::mock(DataShareMirrorSchemaComparator::class),
    );

    expect(fn () => $reviewer->review(DataShareMirrorDirection::Push, $tables))
        ->toThrow(DataShareMirrorException::class);
})->with([
    'empty' => [[]],
    'duplicate' => [['module_records', 'module_records']],
    'malformed' => [['module_records; DROP TABLE users']],
]);

it('determines transfer mode before acquiring review endpoint connections', function (): void {
    $connection = Mockery::mock(Connection::class);
    $schema = Mockery::mock();
    $schema->shouldReceive('hasTable')->andReturnFalse();
    $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
    $connections = Mockery::mock(DataShareMirrorConnectionManager::class);
    $connections->shouldReceive('status')->once()->ordered()->andReturn(new DataShareMirrorConnectionStatus(
        configured: true,
        available: true,
        reachable: true,
        driver: 'pgsql',
        localRole: 'development',
        remoteRole: 'development',
        serverVersion: '17',
        pgDumpVersion: null,
        psqlVersion: null,
        reasonCode: null,
        message: 'Ready.',
        transferMode: 'portable',
    ));
    $connections->shouldReceive('source')->once()->ordered()->andReturn(new DataShareMirrorEndpoint('Local', $connection, [], 'sqlite'));
    $connections->shouldReceive('target')->once()->ordered()->andReturn(new DataShareMirrorEndpoint('Mirror', $connection, [], 'pgsql'));
    $catalog = Mockery::mock(DataShareMirrorCatalog::class);
    $catalog->shouldReceive('catalog')->once()->andReturn([new DataShareMirrorCatalogTable(
        'sbg_records',
        'Sbg',
        'blb/sbg',
        null,
        false,
        false,
        null,
        null,
        true,
    )]);
    $dependencies = Mockery::mock(DataShareMirrorDependencyInspector::class)->shouldIgnoreMissing();
    $dependencies->shouldReceive('foreignKeys')->andReturn([]);
    $dependencies->shouldReceive('uniqueKeys')->andReturn([]);
    $dependencies->shouldReceive('insertionOrder')->andReturn(['sbg_records']);

    $review = (new DataShareMirrorReviewer(
        $connections,
        $catalog,
        $dependencies,
        Mockery::mock(DataShareMirrorSchemaComparator::class)->shouldIgnoreMissing(),
    ))->review(DataShareMirrorDirection::Push, ['sbg_records']);

    expect($review->items)->toHaveCount(1);
});

it('keeps the mirror command read-only and refuses an unspecified selection', function (): void {
    $this->artisan('blb:db:mirror-tables')
        ->expectsOutputToContain('at least one --table')
        ->assertExitCode(2);
});

it('force pushes only the exact selected local tables through the native engine', function (): void {
    $reviewer = Mockery::mock(DataShareMirrorReviewer::class);
    $reviewer->shouldReceive('review')
        ->once()
        ->with(DataShareMirrorDirection::Push, ['sbg_records'])
        ->andReturn(new DataShareMirrorReview(
            DataShareMirrorDirection::Push,
            [new DataShareMirrorReviewItem(
                'sbg_records',
                DataShareMirrorAction::Blocked,
                DataShareMirrorAction::Replace,
                [new DataShareMirrorBlocker('schema_incompatible', 'Schemas differ.')],
            )],
            true,
            ['create' => 0, 'replace' => 0, 'delete' => 0, 'blocked' => 1],
            'force-state',
        ));
    $engine = Mockery::mock(DataShareMirrorEngine::class);
    $engine->shouldReceive('execute')->once()->with(Mockery::on(
        fn (DataShareMirrorReview $review): bool => $review->direction === DataShareMirrorDirection::Push
            && ! $review->hasBlockers
            && count($review->items) === 1
            && $review->items[0]->table === 'sbg_records'
            && $review->items[0]->action === DataShareMirrorAction::Replace,
    ))->andReturn(new DataShareMirrorExecutionResult(
        DataShareMirrorDirection::Push,
        ['create' => 0, 'replace' => 1, 'delete' => 0],
        [['table' => 'sbg_records', 'action' => 'replace']],
    ));
    $engines = Mockery::mock(DataShareMirrorEngineRegistry::class);
    $engines->shouldReceive('forMode')->once()->with('native')->andReturn($engine);
    $lock = Mockery::mock(DataShareMirrorOperationLock::class);
    $lock->shouldReceive('run')->once()->andReturnUsing(fn (callable $operation) => $operation());
    $manager = new DataShareMirrorManager(
        Mockery::mock(DataShareMirrorConnectionManager::class),
        Mockery::mock(DataShareMirrorCatalog::class),
        $reviewer,
        $engines,
        $lock,
        app(DataOperationRecorder::class),
    );

    $result = $manager->forcePush(['sbg_records']);

    // The force push is recorded end-to-end on the durable ledger and the result
    // links to its run.
    expect($result->counts['replace'])->toBe(1)
        ->and($result->runId)->toBeGreaterThan(0);

    $run = DataOperationRun::query()->latest('id')->firstOrFail();
    expect($run->id)->toBe($result->runId)
        ->and($run->operation_type->value)->toBe('mirror_force_push')
        ->and($run->status->value)->toBe('succeeded')
        ->and($run->is_forced)->toBeTrue()
        ->and($run->tables()->where('table_name', 'sbg_records')->exists())->toBeTrue();
});

it('redacts unexpected database diagnostics at the public service boundary', function (): void {
    $catalog = Mockery::mock(DataShareMirrorCatalog::class);
    $catalog->shouldReceive('catalog')->once()->andThrow(new RuntimeException(
        'postgresql://private_user:private_password@private_host.example/database',
    ));
    $manager = new DataShareMirrorManager(
        Mockery::mock(DataShareMirrorConnectionManager::class),
        $catalog,
        Mockery::mock(DataShareMirrorReviewer::class),
        Mockery::mock(DataShareMirrorEngineRegistry::class),
        Mockery::mock(DataShareMirrorOperationLock::class),
        new NullDataOperationRecorder,
    );

    try {
        $manager->catalog();
        $this->fail('The unsafe catalog exception was not wrapped.');
    } catch (DataShareMirrorException $exception) {
        expect($exception->getMessage())
            ->not->toContain('private_user', 'private_password', 'private_host.example');
    }
});

it('renders the Local catalog even when the remote endpoint is unreachable', function (): void {
    TableRegistry::query()->create([
        'table_name' => 'zzz_local_first_probe',
        'module_name' => 'Probe',
        'module_path' => 'app/Modules/Probe',
        'migration_file' => 'probe.php',
    ]);

    $connections = Mockery::mock(DataShareMirrorConnectionManager::class);
    $connections->shouldReceive('local')->andReturn(app('db')->connection());
    $connections->shouldReceive('mirror')->andThrow(DataShareMirrorException::unavailable('remote down'));
    $connections->shouldReceive('provider')->andThrow(new RuntimeException('no provider'));

    $tables = collect((new DataShareMirrorCatalog($connections))->catalog());
    $probe = $tables->firstWhere('table', 'zzz_local_first_probe');

    // Local rows render synchronously; the row stays selectable and remote is
    // reported unavailable rather than falsely missing.
    expect($probe)->not->toBeNull()
        ->and($probe->remoteAvailable)->toBeFalse()
        ->and($probe->supported)->toBeTrue()
        ->and($tables->every(fn ($t): bool => $t->remoteAvailable === false))->toBeTrue();
});

it('captures a labelled retrospective baseline of current Local and remote counts', function (): void {
    $localBuilder = Mockery::mock();
    $localBuilder->shouldReceive('count')->andReturn(10);
    $remoteBuilder = Mockery::mock();
    $remoteBuilder->shouldReceive('count')->andReturn(8);

    $local = Mockery::mock(Connection::class);
    $local->shouldReceive('table')->andReturn($localBuilder);
    $remote = Mockery::mock(Connection::class);
    $remote->shouldReceive('table')->andReturn($remoteBuilder);

    $connections = Mockery::mock(DataShareMirrorConnectionManager::class);
    $connections->shouldReceive('local')->andReturn($local);
    $connections->shouldReceive('mirror')->andReturn($remote);
    $connections->shouldReceive('status')->andReturn(new DataShareMirrorConnectionStatus(
        configured: true, available: true, reachable: true, driver: 'pgsql',
        localRole: 'development', remoteRole: 'development',
        serverVersion: null, pgDumpVersion: null, psqlVersion: null,
        reasonCode: null, message: 'ok', providerKey: 'supabase-test',
    ));

    $reviewer = Mockery::mock(DataShareMirrorReviewer::class);
    $reviewer->shouldReceive('review')->andReturn(new DataShareMirrorReview(
        DataShareMirrorDirection::Push,
        [new DataShareMirrorReviewItem('sbg_records', DataShareMirrorAction::Replace, DataShareMirrorAction::Replace, [])],
        false,
        ['create' => 0, 'replace' => 1, 'delete' => 0, 'blocked' => 0],
        'token',
    ));

    $lock = Mockery::mock(DataShareMirrorOperationLock::class);
    $lock->shouldReceive('run')->once()->andReturnUsing(fn (callable $operation) => $operation());

    $manager = new DataShareMirrorManager(
        $connections,
        Mockery::mock(DataShareMirrorCatalog::class),
        $reviewer,
        Mockery::mock(DataShareMirrorEngineRegistry::class),
        $lock,
        app(DataOperationRecorder::class),
    );

    $runId = $manager->captureBaseline(['sbg_records']);

    $run = DataOperationRun::query()->findOrFail($runId);
    expect($run->operation_type->value)->toBe('mirror_baseline')
        ->and($run->status->value)->toBe('succeeded');

    $summary = $run->tables()->where('table_name', 'sbg_records')->firstOrFail();
    expect($summary->rows_before)->toBe(10)
        ->and($summary->rows_after)->toBe(8)
        ->and($summary->actions)->toBe(['baseline']);

    // The baseline updates the current observation projection so counts render.
    $observation = DataShareMirrorObservation::query()
        ->where('table_name', 'sbg_records')->firstOrFail();
    expect($observation->local_rows)->toBe(10)->and($observation->remote_rows)->toBe(8);
});

it('records a determinate engine failure as failed and an uncertain one as indeterminate', function (): void {
    $review = new DataShareMirrorReview(
        DataShareMirrorDirection::Push,
        [new DataShareMirrorReviewItem('sbg_records', DataShareMirrorAction::Replace, DataShareMirrorAction::Replace, [])],
        false,
        ['create' => 0, 'replace' => 1, 'delete' => 0, 'blocked' => 0],
        'tok',
    );

    $makeManager = function (Throwable $engineError) use ($review): DataShareMirrorManager {
        $reviewer = Mockery::mock(DataShareMirrorReviewer::class);
        $reviewer->shouldReceive('review')->andReturn($review);
        $engine = Mockery::mock(DataShareMirrorEngine::class);
        $engine->shouldReceive('execute')->andThrow($engineError);
        $engines = Mockery::mock(DataShareMirrorEngineRegistry::class);
        $engines->shouldReceive('forMode')->andReturn($engine);
        $lock = Mockery::mock(DataShareMirrorOperationLock::class);
        $lock->shouldReceive('run')->andReturnUsing(fn (callable $operation) => $operation());

        return new DataShareMirrorManager(
            Mockery::mock(DataShareMirrorConnectionManager::class),
            Mockery::mock(DataShareMirrorCatalog::class),
            $reviewer,
            $engines,
            $lock,
            app(DataOperationRecorder::class),
        );
    };

    // A determinate, rollback-safe failure is recorded as failed.
    expect(fn () => $makeManager(DataShareMirrorException::safeFailure('export failed'))->forcePush(['sbg_records']))
        ->toThrow(DataShareMirrorException::class);
    expect(DataOperationRun::query()->latest('id')->firstOrFail()->status->value)->toBe('failed');

    // An uncertain outcome (non-zero psql) is recorded as indeterminate.
    expect(fn () => $makeManager(DataShareMirrorException::processFailed('psql', 1))->forcePush(['sbg_records']))
        ->toThrow(DataShareMirrorException::class);
    expect(DataOperationRun::query()->latest('id')->firstOrFail()->status->value)->toBe('indeterminate');
});

it('builds the Local catalog with no remote call at all', function (): void {
    TableRegistry::query()->create([
        'table_name' => 'zz_local_first_probe',
        'module_name' => 'Probe',
        'module_path' => 'app/Modules/Probe',
        'migration_file' => 'probe.php',
    ]);

    $connections = Mockery::mock(DataShareMirrorConnectionManager::class);
    $connections->shouldReceive('local')->andReturn(app('db')->connection());
    // The remote endpoint must never be opened while building the Local catalog.
    $connections->shouldReceive('mirror')->never();
    $connections->shouldReceive('provider')->andThrow(new RuntimeException('no remote'));

    $tables = collect((new DataShareMirrorCatalog($connections))->localCatalog());
    $probe = $tables->firstWhere('table', 'zz_local_first_probe');

    expect($probe)->not->toBeNull()
        ->and($probe->remoteAvailable)->toBeFalse()
        ->and($probe->supported)->toBeTrue();
});
