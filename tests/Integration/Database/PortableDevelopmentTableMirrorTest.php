<?php

use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorConnectionManager;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorManager;
use App\Base\Settings\Contracts\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const PORTABLE_MIRROR_PARENT = 'zz_portable_mirror_parents';
const PORTABLE_MIRROR_CHILD = 'zz_portable_mirror_children';
const PORTABLE_MIRROR_CONTROL = 'zz_portable_mirror_control';

beforeEach(function (): void {
    if (! portableMirrorPostgresTestsEnabled()) {
        $this->markTestSkipped('Set BLB_POSTGRES_MIRROR_TESTS=true and provide the isolated mirror PostgreSQL database.');
    }

    config([
        'app.env' => 'testing',
        'database.default' => 'sqlite',
        'database.connections.data_share_mirror' => portableMirrorPostgresConfig(),
        'data_share.mirror.lock_timeout_ms' => 500,
        'settings.cache_ttl' => 0,
    ]);
    DB::purge('data_share_mirror');
    portableResetRemote();

    $settings = app(SettingsService::class);
    $settings->set('data_share.instance.id', 'portable-mirror-local');
    $settings->set('data_share.instance.name', 'Portable mirror local');
    $settings->set('data_share.instance.role', 'development');
    $settings->set(DataShareMirrorConnectionManager::PROVIDER_SETTING_KEY, 'supabase');
    $settings->set(DataShareMirrorConnectionManager::SETTING_KEY, portableMirrorPostgresUrl(), encrypted: true);

    portableCreateFixtureSchema();
});

afterEach(function (): void {
    if (portableMirrorPostgresTestsEnabled()) {
        portableDropRemoteTables();
        DB::purge('data_share_mirror');
    }
});

it('mirrors complete selected data in both directions between SQLite and PostgreSQL without client tools', function (): void {
    DB::table(PORTABLE_MIRROR_PARENT)->insert([
        ['id' => 1, 'name' => 'local parent', 'enabled' => true, 'payload' => json_encode(['from' => 'sqlite']), 'amount' => '10.50'],
        ['id' => 2, 'name' => 'local second', 'enabled' => false, 'payload' => null, 'amount' => '0.25'],
    ]);
    DB::table(PORTABLE_MIRROR_CHILD)->insert([
        ['id' => 1, 'parent_id' => 1, 'label' => 'local child'],
    ]);
    DB::connection('data_share_mirror')->table(PORTABLE_MIRROR_PARENT)->insert([
        'id' => 91, 'name' => 'remote stale', 'enabled' => false, 'payload' => null, 'amount' => '99.00',
    ]);
    DB::connection('data_share_mirror')->table(PORTABLE_MIRROR_CONTROL)->insert(['marker' => 'untouched']);

    $manager = app(DataShareMirrorManager::class);
    $status = $manager->status();
    expect($status->available)->toBeTrue()
        ->and($status->localDriver)->toBe('sqlite')
        ->and($status->transferMode)->toBe('portable')
        ->and($status->pgDumpVersion)->toBeNull()
        ->and($status->psqlVersion)->toBeNull();

    $review = $manager->review('push', [PORTABLE_MIRROR_CHILD, PORTABLE_MIRROR_PARENT]);
    expect($review->hasBlockers)->toBeFalse()
        ->and($review->counts['replace'])->toBe(2);
    $manager->execute('push', [PORTABLE_MIRROR_CHILD, PORTABLE_MIRROR_PARENT], $review->stateToken);

    $remote = DB::connection('data_share_mirror');
    expect($remote->table(PORTABLE_MIRROR_PARENT)->orderBy('id')->pluck('name')->all())
        ->toBe(['local parent', 'local second'])
        ->and($remote->table(PORTABLE_MIRROR_CHILD)->value('label'))->toBe('local child')
        ->and($remote->table(PORTABLE_MIRROR_CONTROL)->value('marker'))->toBe('untouched');

    $remote->table(PORTABLE_MIRROR_CHILD)->delete();
    $remote->table(PORTABLE_MIRROR_PARENT)->delete();
    $remote->table(PORTABLE_MIRROR_PARENT)->insert([
        'id' => 7, 'name' => 'remote authority', 'enabled' => true, 'payload' => json_encode(['from' => 'postgres']), 'amount' => '7.75',
    ]);
    $remote->table(PORTABLE_MIRROR_CHILD)->insert([
        'id' => 8, 'parent_id' => 7, 'label' => 'remote child',
    ]);

    $review = $manager->review('pull', [PORTABLE_MIRROR_CHILD, PORTABLE_MIRROR_PARENT]);
    expect($review->hasBlockers)->toBeFalse();
    $manager->execute('pull', [PORTABLE_MIRROR_CHILD, PORTABLE_MIRROR_PARENT], $review->stateToken);

    expect(DB::table(PORTABLE_MIRROR_PARENT)->get(['id', 'name'])->map(fn (object $row): array => (array) $row)->all())
        ->toBe([['id' => 7, 'name' => 'remote authority']])
        ->and(DB::table(PORTABLE_MIRROR_CHILD)->value('label'))->toBe('remote child');
});

it('blocks portable data transfer when a selected schema is absent at one endpoint', function (): void {
    Schema::connection('data_share_mirror')->drop(PORTABLE_MIRROR_CHILD);

    $review = app(DataShareMirrorManager::class)->review('push', [PORTABLE_MIRROR_CHILD]);

    expect($review->hasBlockers)->toBeTrue()
        ->and($review->items[0]->action->value)->toBe('blocked')
        ->and(array_column(array_map(fn ($blocker): array => $blocker->toArray(), $review->items[0]->blockers), 'code'))
        ->toContain('schema_missing_at_endpoint');
});

function portableCreateFixtureSchema(): void
{
    foreach ([null, DataShareMirrorConnectionManager::CONNECTION] as $connectionName) {
        $schema = $connectionName === null ? Schema::getFacadeRoot() : Schema::connection($connectionName);
        $schema->create(PORTABLE_MIRROR_PARENT, function ($table): void {
            $table->id();
            $table->string('name');
            $table->boolean('enabled');
            $table->json('payload')->nullable();
            $table->decimal('amount', 12, 2);
        });
        $schema->create(PORTABLE_MIRROR_CHILD, function ($table): void {
            $table->id();
            $table->foreignId('parent_id')->constrained(PORTABLE_MIRROR_PARENT);
            $table->string('label');
        });
        $schema->create(PORTABLE_MIRROR_CONTROL, function ($table): void {
            $table->id();
            $table->string('marker');
        });

        $connection = $connectionName === null ? DB::connection() : DB::connection($connectionName);
        foreach ([PORTABLE_MIRROR_PARENT, PORTABLE_MIRROR_CHILD, PORTABLE_MIRROR_CONTROL] as $table) {
            $connection->table('base_database_tables')->insert([
                'table_name' => $table,
                'module_name' => 'Portable mirror integration',
                'module_path' => 'app/Modules/Core/User',
                'migration_file' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}

function portableResetRemote(): void
{
    portableDropRemoteTables();
    $schema = Schema::connection('data_share_mirror');
    $schema->create('base_database_tables', function ($table): void {
        $table->id();
        $table->string('table_name')->unique();
        $table->string('module_name')->nullable();
        $table->string('module_path')->nullable();
        $table->string('migration_file')->nullable();
        $table->timestamp('stabilized_at')->nullable();
        $table->unsignedBigInteger('stabilized_by')->nullable();
        $table->timestamps();
    });
    $schema->create('base_settings', function ($table): void {
        $table->id();
        $table->string('key');
        $table->json('value');
        $table->boolean('is_encrypted')->default(false);
        $table->string('scope_type', 50)->nullable();
        $table->unsignedBigInteger('scope_id')->nullable();
        $table->timestamps();
        $table->unique(['key', 'scope_type', 'scope_id']);
    });

    DB::connection('data_share_mirror')->table('base_settings')->insert([
        [
            'key' => 'data_share.instance.id',
            'value' => json_encode('portable-mirror-remote'),
            'is_encrypted' => false,
            'scope_type' => null,
            'scope_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'key' => 'data_share.instance.role',
            'value' => json_encode('development'),
            'is_encrypted' => false,
            'scope_type' => null,
            'scope_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
}

function portableDropRemoteTables(): void
{
    $connection = DB::connection('data_share_mirror');
    foreach ([PORTABLE_MIRROR_CHILD, PORTABLE_MIRROR_PARENT, PORTABLE_MIRROR_CONTROL, 'base_settings', 'base_database_tables'] as $table) {
        $connection->statement('DROP TABLE IF EXISTS public."'.$table.'" CASCADE');
    }
}

function portableMirrorPostgresTestsEnabled(): bool
{
    return filter_var(env('BLB_POSTGRES_MIRROR_TESTS', false), FILTER_VALIDATE_BOOL);
}

/** @return array<string, mixed> */
function portableMirrorPostgresConfig(): array
{
    return [
        'driver' => 'pgsql',
        'host' => (string) env('MIRROR_TEST_DB_HOST', '127.0.0.1'),
        'port' => (int) env('MIRROR_TEST_DB_PORT', 5432),
        'database' => (string) env('MIRROR_TEST_DB_DATABASE', 'blb_mirror_target'),
        'username' => (string) env('MIRROR_TEST_DB_USERNAME', 'postgres'),
        'password' => (string) env('MIRROR_TEST_DB_PASSWORD', ''),
        'charset' => 'utf8',
        'prefix' => '',
        'prefix_indexes' => true,
        'search_path' => 'public',
        'sslmode' => 'disable',
    ];
}

function portableMirrorPostgresUrl(): string
{
    $config = portableMirrorPostgresConfig();

    return sprintf(
        'postgresql://%s:%s@%s:%d/%s?sslmode=disable',
        rawurlencode((string) $config['username']),
        rawurlencode((string) $config['password']),
        (string) $config['host'],
        (int) $config['port'],
        rawurlencode((string) $config['database']),
    );
}
