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
    );

    expect($manager->forcePush(['sbg_records'])->counts['replace'])->toBe(1);
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
    );

    try {
        $manager->catalog();
        $this->fail('The unsafe catalog exception was not wrapped.');
    } catch (DataShareMirrorException $exception) {
        expect($exception->getMessage())
            ->not->toContain('private_user', 'private_password', 'private_host.example');
    }
});
