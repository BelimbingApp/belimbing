<?php

use App\Base\Database\DTO\Bridge\BridgeExportResult;
use App\Base\Database\DTO\Bridge\BridgeInstanceIdentity;
use App\Base\Database\DTO\Bridge\BridgeReceiveGrantBundle;
use App\Base\Database\DTO\Bridge\BridgeTableDefinition;
use App\Base\Database\Enums\BridgeInstanceRole;
use App\Base\Database\Exceptions\BridgeApplyException;
use App\Base\Database\Exceptions\BridgeDefinitionException;
use App\Base\Database\Exceptions\BridgePackageException;
use App\Base\Database\Exceptions\BridgeTransportException;
use App\Base\Database\Livewire\Bridge\Index as BridgeIndex;
use App\Base\Database\Livewire\Bridge\Settings as BridgeSettingsPage;
use App\Base\Database\Models\BridgeEvent;
use App\Base\Database\Models\BridgePlan;
use App\Base\Database\Models\BridgeReceipt;
use App\Base\Database\Models\BridgeReceiveGrant;
use App\Base\Database\Models\TableRegistry;
use App\Base\Database\Services\Bridge\BridgeDestinationMapper;
use App\Base\Database\Services\Bridge\BridgeImportPlanner;
use App\Base\Database\Services\Bridge\BridgePackageApplier;
use App\Base\Database\Services\Bridge\BridgePackageExporter;
use App\Base\Database\Services\Bridge\BridgePackageInbox;
use App\Base\Database\Services\Bridge\BridgePackageRetention;
use App\Base\Database\Services\Bridge\BridgePackageSender;
use App\Base\Database\Services\Bridge\BridgePackageVerifier;
use App\Base\Database\Services\Bridge\BridgeReceiveGrantManager;
use App\Base\Database\Services\Bridge\BridgeScopeCatalog;
use App\Base\Database\Services\Bridge\BridgeSettings;
use App\Base\Database\Services\Bridge\BridgeValueNormalizer;
use App\Base\Database\Services\Bridge\CanonicalJson;
use App\Base\Settings\Contracts\SettingsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\Process\Process;

const GENERIC_BRIDGE_SCOPE = 'tests/fixtures/data-export';
const GENERIC_BRIDGE_PARENT = 'test_bridge_parents';
const GENERIC_BRIDGE_CHILD = 'test_bridge_children';

beforeEach(function (): void {
    Storage::fake('local');
    config([
        'app.env' => 'testing',
        'bridge.disk' => 'local',
        'bridge.instance.id' => 'generic-source-dev',
        'bridge.instance.name' => 'Generic source',
        'bridge.instance.role' => 'development',
        'bridge.outgoing_path_prefix' => 'bridge/outgoing',
        'bridge.incoming_path_prefix' => 'bridge/incoming',
        'bridge.receiving_path_prefix' => 'bridge/receiving',
        'bridge.receive_grants.expiry_minutes' => 15,
    ]);

    Schema::create(GENERIC_BRIDGE_PARENT, function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->string('code')->unique();
        $table->string('nullable_alias')->nullable()->unique();
        $table->string('name');
        $table->json('metadata')->nullable();
        $table->date('effective_on')->nullable();
        $table->decimal('amount', 16, 4);
        $table->binary('payload')->nullable();
    });
    Schema::create(GENERIC_BRIDGE_CHILD, function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('parent_id');
        $table->string('external_code')->unique();
        $table->text('note')->nullable();
        $table->foreign('parent_id')->references('id')->on(GENERIC_BRIDGE_PARENT);
    });
    TableRegistry::register(GENERIC_BRIDGE_PARENT, 'Bridge Fixture', GENERIC_BRIDGE_SCOPE, 'test');
    TableRegistry::register(GENERIC_BRIDGE_CHILD, 'Bridge Fixture', GENERIC_BRIDGE_SCOPE, 'test');
});

afterEach(function (): void {
    TableRegistry::unregister(GENERIC_BRIDGE_CHILD);
    TableRegistry::unregister(GENERIC_BRIDGE_PARENT);
    Schema::dropIfExists(GENERIC_BRIDGE_CHILD);
    Schema::dropIfExists(GENERIC_BRIDGE_PARENT);
});

function seedGenericBridgeFixture(): void
{
    DB::table(GENERIC_BRIDGE_PARENT)->insert([
        [
            'id' => 2,
            'code' => 'parent-2',
            'nullable_alias' => null,
            'name' => 'Éclair شركة',
            'metadata' => json_encode(['nested' => ['ready' => true]], JSON_THROW_ON_ERROR),
            'effective_on' => '2026-07-10',
            'amount' => '12.3400',
            'payload' => "\x00\xFFbridge",
        ],
        [
            'id' => 10,
            'code' => 'parent-10',
            'nullable_alias' => null,
            'name' => 'Ten',
            'metadata' => null,
            'effective_on' => null,
            'amount' => '0.5000',
            'payload' => null,
        ],
    ]);
    DB::table(GENERIC_BRIDGE_CHILD)->insert([
        'id' => 25,
        'parent_id' => 10,
        'external_code' => 'child-25',
        'note' => 'Relationship must keep the physical parent key.',
    ]);
}

function genericBridgeSource(): BridgeInstanceIdentity
{
    return new BridgeInstanceIdentity('generic-source-dev', 'Generic source', BridgeInstanceRole::Development);
}

function becomeGenericBridgeSource(): void
{
    config([
        'bridge.instance.id' => 'generic-source-dev',
        'bridge.instance.name' => 'Generic source',
        'bridge.instance.role' => 'development',
    ]);
}

function becomeGenericBridgeDestination(): BridgeInstanceIdentity
{
    config([
        'bridge.instance.id' => 'generic-destination-stage',
        'bridge.instance.name' => 'Generic destination',
        'bridge.instance.role' => 'staging',
    ]);

    return new BridgeInstanceIdentity(
        'generic-destination-stage',
        'Generic destination',
        BridgeInstanceRole::Staging,
    );
}

function issueGenericBridgeGrant(): BridgeReceiveGrantBundle
{
    becomeGenericBridgeDestination();
    $bundle = app(BridgeReceiveGrantManager::class)->issue(
        genericBridgeSource(),
        GENERIC_BRIDGE_SCOPE,
        actorId: 9001,
    );
    becomeGenericBridgeSource();

    return $bundle;
}

function becomeGenericBridgeProductionDestination(): BridgeInstanceIdentity
{
    config([
        'bridge.instance.id' => 'generic-destination-production',
        'bridge.instance.name' => 'Generic production destination',
        'bridge.instance.role' => 'production',
    ]);

    return new BridgeInstanceIdentity(
        'generic-destination-production',
        'Generic production destination',
        BridgeInstanceRole::Production,
    );
}

function issueGenericBridgeProductionGrant(): BridgeReceiveGrantBundle
{
    becomeGenericBridgeProductionDestination();
    $bundle = app(BridgeReceiveGrantManager::class)->issue(
        genericBridgeSource(),
        GENERIC_BRIDGE_SCOPE,
        actorId: 9001,
    );
    becomeGenericBridgeSource();

    return $bundle;
}

/** @return array{export: BridgeExportResult, grant: BridgeReceiveGrantBundle} */
function exportGenericBridgeFixture(
    ?BridgeReceiveGrantBundle $grant = null,
    array $tables = [GENERIC_BRIDGE_PARENT, GENERIC_BRIDGE_CHILD],
): array {
    $grant ??= issueGenericBridgeGrant();
    becomeGenericBridgeSource();
    $exporter = app(BridgePackageExporter::class);
    $preview = $exporter->preview(GENERIC_BRIDGE_SCOPE, $tables, $grant);
    $export = $exporter->export(GENERIC_BRIDGE_SCOPE, $tables, $grant, $preview->previewHash);

    return compact('export', 'grant');
}

function receiveGenericBridgePackage(BridgeReceiveGrantBundle $bundle, BridgeExportResult $export): BridgeReceipt
{
    becomeGenericBridgeDestination();
    $grant = app(BridgeReceiveGrantManager::class)->authenticate($bundle->grantId, $bundle->secret);

    return app(BridgePackageInbox::class)->receiveFromProtectedPath($export->path, $grant);
}

function receiveGenericBridgeProductionPackage(BridgeReceiveGrantBundle $bundle, BridgeExportResult $export): BridgeReceipt
{
    becomeGenericBridgeProductionDestination();
    $grant = app(BridgeReceiveGrantManager::class)->authenticate($bundle->grantId, $bundle->secret);

    return app(BridgePackageInbox::class)->receiveFromProtectedPath($export->path, $grant);
}

it('discovers a module scope and relational contract without module bridge code', function (): void {
    $scope = app(BridgeScopeCatalog::class)->scope(GENERIC_BRIDGE_SCOPE);

    expect(array_column($scope->tables, 'table'))->toBe([GENERIC_BRIDGE_PARENT, GENERIC_BRIDGE_CHILD])
        ->and($scope->tables[0]->primaryKeyColumns)->toBe(['id'])
        ->and($scope->tables[1]->references)->toHaveCount(1)
        ->and($scope->tables[1]->references[0]->targetTable)->toBe(GENERIC_BRIDGE_PARENT);
});

it('rejects a selected foreign-key cycle that has no generic insert order', function (): void {
    $first = 'test_bridge_cycle_first';
    $second = 'test_bridge_cycle_second';
    $scope = 'tests/fixtures/data-export-cycle';
    Schema::disableForeignKeyConstraints();

    try {
        DB::statement("CREATE TABLE {$first} (id INTEGER PRIMARY KEY, second_id INTEGER NOT NULL, FOREIGN KEY(second_id) REFERENCES {$second}(id))");
        DB::statement("CREATE TABLE {$second} (id INTEGER PRIMARY KEY, first_id INTEGER NOT NULL, FOREIGN KEY(first_id) REFERENCES {$first}(id))");
        TableRegistry::register($first, 'Bridge Cycle Fixture', $scope, 'test');
        TableRegistry::register($second, 'Bridge Cycle Fixture', $scope, 'test');

        expect(fn () => app(BridgeScopeCatalog::class)->scope($scope))
            ->toThrow(BridgeDefinitionException::class, 'foreign-key cycle');
    } finally {
        TableRegistry::unregister($second);
        TableRegistry::unregister($first);
        Schema::dropIfExists($second);
        Schema::dropIfExists($first);
        Schema::enableForeignKeyConstraints();
    }
});

it('issues a copy-once receive key while persisting only its hash and public policy', function (): void {
    config(['bridge.receive_grants.base_urls' => [
        'https://livenpc.lan:8443',
        'https://bridge.example.test',
    ]]);
    $bundle = issueGenericBridgeGrant();
    $grant = BridgeReceiveGrant::query()->where('grant_id', $bundle->grantId)->firstOrFail();
    $decoded = BridgeReceiveGrantBundle::fromJson($bundle->toJson());

    expect($bundle->secret)->toHaveLength(43)
        ->and($grant->secret_hash)->toBe(hash('sha256', $bundle->secret))
        ->and(json_encode($grant->toArray(), JSON_THROW_ON_ERROR))->not->toContain($bundle->secret)
        ->and($decoded->grantId)->toBe($bundle->grantId)
        ->and($decoded->expectedSource->id)->toBe('generic-source-dev')
        ->and($decoded->target->id)->toBe('generic-destination-stage')
        ->and($decoded->scope)->toBe(GENERIC_BRIDGE_SCOPE)
        ->and($decoded->endpoints)->toBe([
            'https://livenpc.lan:8443/data-bridge/receive/'.$bundle->grantId,
            'https://bridge.example.test/data-bridge/receive/'.$bundle->grantId,
        ])
        ->and($decoded->endpoint)->toBe($decoded->endpoints[0])
        ->and($grant->issued_by_actor_id)->toBe(9001);

    expect(fn () => $decoded->usingEndpoint('https://other.example.test/data-bridge/receive/'.$bundle->grantId))
        ->toThrow(BridgeTransportException::class, 'malformed');
});

it('allows only one of two pre-authenticated requests to consume a receive key', function (): void {
    $bundle = issueGenericBridgeGrant();
    becomeGenericBridgeDestination();
    $manager = app(BridgeReceiveGrantManager::class);
    $first = $manager->authenticate($bundle->grantId, $bundle->secret);
    $second = $manager->authenticate($bundle->grantId, $bundle->secret);
    $manager->consume($first->id, str_repeat('a', 64));

    expect(fn () => $manager->consume($second->id, str_repeat('b', 64)))
        ->toThrow(BridgeTransportException::class, 'already consumed or became unavailable');
});

it('allows only one process to consume a receive key on file-backed SQLite', function (): void {
    $token = bin2hex(random_bytes(8));
    $database = storage_path("framework/testing/data-bridge-race-{$token}.sqlite");
    $worker = storage_path("framework/testing/data-bridge-race-{$token}.php");
    $hashes = [str_repeat('a', 64), str_repeat('b', 64)];

    try {
        $pdo = new PDO('sqlite:'.$database);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('CREATE TABLE base_database_bridge_receive_grants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            grant_id TEXT NOT NULL UNIQUE,
            secret_hash TEXT NOT NULL,
            issued_by_actor_id INTEGER NULL,
            expected_source_instance_id TEXT NOT NULL,
            expected_source_role TEXT NOT NULL,
            target_instance_id TEXT NOT NULL,
            target_role TEXT NOT NULL,
            scope_name TEXT NOT NULL,
            max_bytes INTEGER NOT NULL,
            status TEXT NOT NULL,
            consumed_package_sha256 TEXT NULL,
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        )');
        $pdo->exec('CREATE TABLE base_database_bridge_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            package_id TEXT NULL,
            plan_hash TEXT NULL,
            action TEXT NOT NULL,
            actor_id INTEGER NULL,
            source_instance_id TEXT NULL,
            target_instance_id TEXT NULL,
            scope_name TEXT NULL,
            metadata TEXT NULL,
            error_summary TEXT NULL,
            created_at DATETIME NOT NULL
        )');
        $insert = $pdo->prepare('INSERT INTO base_database_bridge_receive_grants (
            grant_id, secret_hash, issued_by_actor_id, expected_source_instance_id,
            expected_source_role, target_instance_id, target_role, scope_name,
            max_bytes, status, expires_at, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $now = now('UTC');
        $insert->execute([
            '01j00000000000000000000000',
            hash('sha256', str_repeat('A', 43)),
            9001,
            'race-source',
            'development',
            'race-target',
            'staging',
            GENERIC_BRIDGE_SCOPE,
            262144000,
            'issued',
            $now->copy()->addHour()->toDateTimeString(),
            $now->toDateTimeString(),
            $now->toDateTimeString(),
        ]);
        file_put_contents($worker, <<<'PHP'
<?php

[$script, $database, $hash, $startAt] = $argv;
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE='.$database);
putenv('CACHE_STORE=array');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;
$_ENV['CACHE_STORE'] = $_SERVER['CACHE_STORE'] = 'array';
$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

while (microtime(true) < (float) $startAt) {
    usleep(1000);
}

try {
    app(App\Base\Database\Services\Bridge\BridgeReceiveGrantManager::class)->consume(1, $hash);
    echo 'consumed:'.$hash;
} catch (App\Base\Database\Exceptions\BridgeTransportException) {
    echo 'conflict';
}
PHP);
        $startAt = microtime(true) + 1;
        $processes = array_map(function (string $hash) use ($worker, $database, $startAt): Process {
            $process = new Process([PHP_BINARY, $worker, $database, $hash, (string) $startAt]);
            $process->setTimeout(30);
            $process->start();

            return $process;
        }, $hashes);
        $outputs = array_map(function (Process $process): string {
            $process->wait();

            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

            return trim($process->getOutput());
        }, $processes);
        sort($outputs);
        $storedHash = (string) $pdo->query('SELECT consumed_package_sha256 FROM base_database_bridge_receive_grants WHERE id = 1')->fetchColumn();
        $eventCount = (int) $pdo->query("SELECT COUNT(*) FROM base_database_bridge_events WHERE action = 'grant_consumed'")->fetchColumn();

        expect($outputs[0])->toBe('conflict')
            ->and($outputs[1])->toStartWith('consumed:')
            ->and($hashes)->toContain($storedHash)
            ->and($eventCount)->toBe(1);
    } finally {
        unset($pdo);

        foreach ([$worker, $database, $database.'-wal', $database.'-shm'] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
});

it('lets an authorized target user issue and permanently hide a copy-once receive key', function (): void {
    becomeGenericBridgeDestination();
    $this->actingAs(createAdminUser());

    $component = Livewire::test(BridgeIndex::class)
        ->set('grantSourceId', 'generic-source-dev')
        ->set('grantSourceName', 'Generic source')
        ->set('grantSourceRole', 'development')
        ->set('grantScope', GENERIC_BRIDGE_SCOPE)
        ->call('issueReceiveGrant')
        ->assertSet('statusVariant', 'success');

    $bundle = BridgeReceiveGrantBundle::fromJson($component->get('issuedReceiveBundle'));
    $grant = BridgeReceiveGrant::query()->where('grant_id', $bundle->grantId)->firstOrFail();

    expect($grant->issued_by_actor_id)->toBe(auth()->id())
        ->and($grant->secret_hash)->toBe(hash('sha256', $bundle->secret));

    $component->call('clearIssuedReceiveBundle')->assertSet('issuedReceiveBundle', null);
});

it('stores Data Bridge operator configuration in Base Settings and uses it at runtime', function (): void {
    $this->actingAs(createAdminUser());
    $this->get(route('admin.system.database-bridge.settings'))
        ->assertOk()
        ->assertSee('Data Bridge Settings');

    $component = Livewire::test(BridgeSettingsPage::class)
        ->assertSee('Data Bridge Settings')
        ->assertSee('Advertised HTTPS routes')
        ->assertSet('values.bridge__instance__id', 'generic-source-dev')
        ->assertSet('values.bridge__instance__name', 'Generic source')
        ->assertSet('values.bridge__instance__role', 'development')
        ->set('values.bridge__instance__id', 'settings-target')
        ->set('values.bridge__instance__name', 'Settings target')
        ->set('values.bridge__instance__role', 'staging')
        ->set('values.bridge__receive_grants__base_urls', "https://settings-target.internal\nhttps://settings-target.example.test")
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(SettingsService::class);
    $bundle = app(BridgeReceiveGrantManager::class)->issue(
        new BridgeInstanceIdentity('settings-source', 'Settings source', BridgeInstanceRole::Development),
        GENERIC_BRIDGE_SCOPE,
    );

    expect($settings->get('bridge.instance.id'))->toBe('settings-target')
        ->and($settings->get('bridge.instance.role'))->toBe('staging')
        ->and(app(BridgeSettings::class)->stringList('bridge.receive_grants.base_urls'))->toBe([
            'https://settings-target.internal',
            'https://settings-target.example.test',
        ])
        ->and($bundle->target->id)->toBe('settings-target')
        ->and($bundle->endpoints)->toBe([
            'https://settings-target.internal/data-bridge/receive/'.$bundle->grantId,
            'https://settings-target.example.test/data-bridge/receive/'.$bundle->grantId,
        ]);
});

it('refuses unsafe Data Bridge settings before they reach Base Settings', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(BridgeSettingsPage::class)
        ->set('values.bridge__receive_grants__base_urls', 'http://target.example.test?token=bad')
        ->call('save')
        ->assertHasErrors(['values.bridge__receive_grants__base_urls']);

    Livewire::test(BridgeSettingsPage::class)
        ->set('values.bridge__disk', 'public')
        ->call('save')
        ->assertHasErrors(['values.bridge__disk']);

    Livewire::test(BridgeSettingsPage::class)
        ->set('values.bridge__incoming_path_prefix', 'bridge')
        ->call('save')
        ->assertHasErrors(['values.bridge__outgoing_path_prefix']);

    expect(app(SettingsService::class)->has('bridge.receive_grants.base_urls'))->toBeFalse()
        ->and(app(SettingsService::class)->has('bridge.disk'))->toBeFalse()
        ->and(app(SettingsService::class)->has('bridge.incoming_path_prefix'))->toBeFalse();
});

it('resolves each Base Setting only once per bridge service instance', function (): void {
    $settingsService = Mockery::mock(SettingsService::class);
    $settingsService->shouldReceive('get')
        ->once()
        ->with('bridge.transfer_limits.max_records', 250000)
        ->andReturn('42');
    $settings = new BridgeSettings($settingsService);

    expect($settings->integer('bridge.transfer_limits.max_records', 250000, 1, 10000000))->toBe(42)
        ->and($settings->integer('bridge.transfer_limits.max_records', 250000, 1, 10000000))->toBe(42);
});

it('applies a pasted receive key to the exact target scope and whole-module selection', function (): void {
    seedGenericBridgeFixture();
    config(['bridge.receive_grants.base_urls' => [
        'https://livenpc.lan:8443',
        'https://bridge.example.test',
    ]]);
    $bundle = issueGenericBridgeGrant();
    becomeGenericBridgeSource();
    $this->actingAs(createAdminUser());

    $component = Livewire::test(BridgeIndex::class)
        ->set('receiveBundle', $bundle->toJson())
        ->call('applyReceiveBundle')
        ->assertSet('statusVariant', 'success')
        ->assertSet('scopeName', GENERIC_BRIDGE_SCOPE)
        ->assertSet('targetId', 'generic-destination-stage')
        ->assertSet('targetEndpoints', $bundle->endpoints)
        ->assertSet('targetEndpoint', $bundle->endpoints[0])
        ->assertSet('selectedTables', [GENERIC_BRIDGE_PARENT, GENERIC_BRIDGE_CHILD])
        ->assertSee('Transport route')
        ->assertSee('livenpc.lan:8443')
        ->call('previewExport')
        ->assertSet('statusVariant', 'success');
    $firstHash = $component->get('exportPreview')['preview_sha256'];
    $component
        ->set('targetEndpoint', $bundle->endpoints[1])
        ->assertSet('exportPreview', null)
        ->call('previewExport')
        ->assertSet('statusVariant', 'success');

    expect($component->get('exportPreview')['preview_sha256'])->toBe($firstHash);
});

it('exports deterministic bounded table payloads with physical identities and binary fidelity', function (): void {
    seedGenericBridgeFixture();
    $grant = issueGenericBridgeGrant();
    $exporter = app(BridgePackageExporter::class);
    $first = $exporter->preview(GENERIC_BRIDGE_SCOPE, [GENERIC_BRIDGE_PARENT, GENERIC_BRIDGE_CHILD], $grant);
    $second = $exporter->preview(GENERIC_BRIDGE_SCOPE, [GENERIC_BRIDGE_PARENT, GENERIC_BRIDGE_CHILD], $grant);
    $result = $exporter->export(
        GENERIC_BRIDGE_SCOPE,
        [GENERIC_BRIDGE_PARENT, GENERIC_BRIDGE_CHILD],
        $grant,
        $second->previewHash,
    );
    $lines = explode("\n", trim((string) Storage::disk('local')->get($result->path)));
    $parent = collect($lines)
        ->map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR))
        ->first(fn (array $line): bool => ($line['kind'] ?? null) === 'row'
            && ($line['table'] ?? null) === GENERIC_BRIDGE_PARENT
            && ($line['primary_key']['id'] ?? null) === 2);

    expect($first->report['payloads'])->toBe($second->report['payloads'])
        ->and($first->previewHash)->toBe($second->previewHash)
        ->and($first->report['receive_grant_id'])->toBe($grant->grantId)
        ->and($parent['primary_key'])->toBe(['id' => 2])
        ->and($parent['values']['id'])->toBe(2)
        ->and($parent['values']['metadata'])->toBe('{"nested":{"ready":true}}')
        ->and($parent['values']['amount'])->toBe('12.34')
        ->and($parent['values']['payload'])->toBe(['__bridge_binary_base64' => base64_encode("\x00\xFFbridge")])
        ->and(Storage::disk('local')->get($result->path))->not->toContain($grant->secret)
        ->and($result->bytes)->toBeLessThan((int) config('bridge.transfer_limits.max_package_bytes'));
});

it('enforces scalar, canonical-line, record, and table bounds before export', function (array $limits, string $message): void {
    seedGenericBridgeFixture();
    $grant = issueGenericBridgeGrant();
    config($limits);

    expect(fn () => app(BridgePackageExporter::class)->preview(
        GENERIC_BRIDGE_SCOPE,
        [GENERIC_BRIDGE_PARENT, GENERIC_BRIDGE_CHILD],
        $grant,
    ))->toThrow(BridgePackageException::class, $message);
})->with([
    'scalar' => [['bridge.transfer_limits.max_scalar_bytes' => 4], 'transfer scalar limit'],
    'canonical line' => [['bridge.transfer_limits.max_record_line_bytes' => 128], 'line limit'],
    'records' => [['bridge.transfer_limits.max_records' => 1], 'record limit'],
    'tables' => [['bridge.transfer_limits.max_tables' => 1], 'table limit'],
]);

it('plans and applies inserts, preserves relationships, rejects replay, and makes a later package a no-op', function (): void {
    seedGenericBridgeFixture();
    ['export' => $export, 'grant' => $grant] = exportGenericBridgeFixture();
    DB::table(GENERIC_BRIDGE_CHILD)->delete();
    DB::table(GENERIC_BRIDGE_PARENT)->delete();
    $receipt = receiveGenericBridgePackage($grant, $export);
    $plan = app(BridgeImportPlanner::class)->plan($receipt);

    expect($plan->status)->toBe('ready')
        ->and($plan->summary['counts'])->toBe(['insert' => 3, 'unchanged' => 0, 'conflict' => 0]);

    $result = app(BridgePackageApplier::class)->apply(
        $plan,
        $receipt->package_sha256,
        $plan->plan_hash,
        confirmed: true,
    );
    $parent = (array) DB::table(GENERIC_BRIDGE_PARENT)->where('id', 2)->first();
    $child = (array) DB::table(GENERIC_BRIDGE_CHILD)->where('id', 25)->first();

    expect($result->counts['insert'])->toBe(3)
        ->and($parent['name'])->toBe('Éclair شركة')
        ->and($parent['payload'])->toBe("\x00\xFFbridge")
        ->and((int) $child['parent_id'])->toBe(10)
        ->and(BridgeEvent::query()->pluck('action')->all())
        ->toContain('grant_issued', 'exported', 'grant_consumed', 'received', 'planned', 'applied');

    expect(fn () => app(BridgePackageApplier::class)->apply(
        $plan->refresh(),
        $receipt->package_sha256,
        $plan->plan_hash,
        confirmed: true,
    ))->toThrow(BridgeApplyException::class, 'already been applied');

    ['export' => $secondExport, 'grant' => $secondGrant] = exportGenericBridgeFixture();
    $secondReceipt = receiveGenericBridgePackage($secondGrant, $secondExport);
    $secondPlan = app(BridgeImportPlanner::class)->plan($secondReceipt);

    expect($secondPlan->status)->toBe('ready')
        ->and($secondPlan->summary['counts'])->toBe(['insert' => 0, 'unchanged' => 3, 'conflict' => 0]);
});

it('refuses apply while the global bridge lock is held', function (): void {
    seedGenericBridgeFixture();
    ['export' => $export, 'grant' => $grant] = exportGenericBridgeFixture();
    $receipt = receiveGenericBridgePackage($grant, $export);
    $plan = app(BridgeImportPlanner::class)->plan($receipt);
    $lock = Cache::lock('base:database-bridge:apply', 900);

    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => app(BridgePackageApplier::class)->apply(
            $plan,
            $receipt->package_sha256,
            $plan->plan_hash,
            confirmed: true,
        ))->toThrow(BridgeApplyException::class, 'already running');
    } finally {
        $lock->release();
    }
});

it('blocks production apply before mutation when a recovery point cannot be created', function (): void {
    seedGenericBridgeFixture();
    $grant = issueGenericBridgeProductionGrant();
    ['export' => $export] = exportGenericBridgeFixture($grant);
    DB::table(GENERIC_BRIDGE_CHILD)->delete();
    DB::table(GENERIC_BRIDGE_PARENT)->delete();
    $receipt = receiveGenericBridgeProductionPackage($grant, $export);
    $plan = app(BridgeImportPlanner::class)->plan($receipt);
    config(['backup.enabled' => false]);

    expect(fn () => app(BridgePackageApplier::class)->apply(
        $plan,
        $receipt->package_sha256,
        $plan->plan_hash,
        confirmed: true,
    ))->toThrow(BridgeApplyException::class, 'fresh verified backup');

    expect(DB::table(GENERIC_BRIDGE_PARENT)->count())->toBe(0)
        ->and(DB::table(GENERIC_BRIDGE_CHILD)->count())->toBe(0)
        ->and($plan->refresh()->status)->toBe('ready')
        ->and(BridgeEvent::query()->where('action', 'apply_failed')->exists())->toBeTrue();
});

it('rolls back a partial transaction and succeeds on a clean retry', function (): void {
    seedGenericBridgeFixture();
    ['export' => $export, 'grant' => $grant] = exportGenericBridgeFixture();
    DB::table(GENERIC_BRIDGE_CHILD)->delete();
    DB::table(GENERIC_BRIDGE_PARENT)->delete();
    $receipt = receiveGenericBridgePackage($grant, $export);
    $plan = app(BridgeImportPlanner::class)->plan($receipt);
    $failingMapper = new class(app(BridgeValueNormalizer::class), app(BridgeScopeCatalog::class)) extends BridgeDestinationMapper
    {
        private int $findCalls = 0;

        public function findExisting(BridgeTableDefinition $table, array $record): ?array
        {
            if (++$this->findCalls === 6) {
                throw new RuntimeException('Injected bridge transaction failure.');
            }

            return parent::findExisting($table, $record);
        }
    };
    app()->instance(BridgeDestinationMapper::class, $failingMapper);

    expect(fn () => app(BridgePackageApplier::class)->apply(
        $plan,
        $receipt->package_sha256,
        $plan->plan_hash,
        confirmed: true,
    ))->toThrow(RuntimeException::class, 'Injected bridge transaction failure');

    expect(DB::table(GENERIC_BRIDGE_PARENT)->count())->toBe(0)
        ->and(DB::table(GENERIC_BRIDGE_CHILD)->count())->toBe(0)
        ->and($plan->refresh()->status)->toBe('ready')
        ->and(BridgeEvent::query()->where('action', 'apply_failed')->exists())->toBeTrue();

    app()->instance(
        BridgeDestinationMapper::class,
        new BridgeDestinationMapper(app(BridgeValueNormalizer::class), app(BridgeScopeCatalog::class)),
    );
    $result = app(BridgePackageApplier::class)->apply(
        $plan->refresh(),
        $receipt->package_sha256,
        $plan->plan_hash,
        confirmed: true,
    );

    expect($result->counts['insert'])->toBe(3)
        ->and(DB::table(GENERIC_BRIDGE_PARENT)->count())->toBe(2)
        ->and(DB::table(GENERIC_BRIDGE_CHILD)->count())->toBe(1);
});

it('builds the same plan hash for unchanged package and destination state', function (): void {
    seedGenericBridgeFixture();
    ['export' => $export, 'grant' => $grant] = exportGenericBridgeFixture();
    $receipt = receiveGenericBridgePackage($grant, $export);
    $planner = app(BridgeImportPlanner::class);
    $first = $planner->plan($receipt);
    $second = $planner->plan($receipt->refresh());

    expect($first->plan_hash)->toBe($second->plan_hash)
        ->and($first->destination_fingerprint)->toBe($second->destination_fingerprint);
});

it('blocks primary-key divergence, unique collisions, and missing foreign-key parents', function (): void {
    seedGenericBridgeFixture();
    ['export' => $export, 'grant' => $grant] = exportGenericBridgeFixture();
    DB::table(GENERIC_BRIDGE_CHILD)->delete();
    DB::table(GENERIC_BRIDGE_PARENT)->where('id', 2)->update(['name' => 'Destination changed']);
    DB::table(GENERIC_BRIDGE_PARENT)->where('id', 10)->delete();
    DB::table(GENERIC_BRIDGE_PARENT)->insert([
        'id' => 99,
        'code' => 'parent-10',
        'nullable_alias' => null,
        'name' => 'Unique collision',
        'metadata' => null,
        'effective_on' => null,
        'amount' => '1.0000',
        'payload' => null,
    ]);
    $plan = app(BridgeImportPlanner::class)->plan(receiveGenericBridgePackage($grant, $export));

    expect($plan->status)->toBe('conflicts')
        ->and($plan->summary['counts']['conflict'])->toBe(3)
        ->and($plan->summary['counts']['insert'])->toBe(0);
});

it('allows nullable unique values when another destination row is null', function (): void {
    seedGenericBridgeFixture();
    ['export' => $export, 'grant' => $grant] = exportGenericBridgeFixture(tables: [GENERIC_BRIDGE_PARENT]);
    DB::table(GENERIC_BRIDGE_CHILD)->delete();
    DB::table(GENERIC_BRIDGE_PARENT)->delete();
    DB::table(GENERIC_BRIDGE_PARENT)->insert([
        'id' => 99,
        'code' => 'destination-only',
        'nullable_alias' => null,
        'name' => 'Destination only',
        'metadata' => null,
        'effective_on' => null,
        'amount' => '1.0000',
        'payload' => null,
    ]);
    $plan = app(BridgeImportPlanner::class)->plan(receiveGenericBridgePackage($grant, $export));

    expect($plan->status)->toBe('ready')
        ->and($plan->summary['counts'])->toBe(['insert' => 2, 'unchanged' => 0, 'conflict' => 0]);
});

it('invalidates a reviewed plan when destination data changes', function (): void {
    seedGenericBridgeFixture();
    ['export' => $export, 'grant' => $grant] = exportGenericBridgeFixture();
    $receipt = receiveGenericBridgePackage($grant, $export);
    $plan = app(BridgeImportPlanner::class)->plan($receipt);
    DB::table(GENERIC_BRIDGE_PARENT)->where('id', 2)->update(['name' => 'Changed after planning']);

    app(BridgePackageApplier::class)->apply(
        $plan,
        $receipt->package_sha256,
        $plan->plan_hash,
        confirmed: true,
    );
})->throws(BridgeApplyException::class, 'Destination data changed after preview');

it('rejects expired packages, wrong targets, and destination schema drift before Incoming', function (string $failure): void {
    seedGenericBridgeFixture();
    ['export' => $export, 'grant' => $bundle] = exportGenericBridgeFixture();
    $grant = BridgeReceiveGrant::query()->where('grant_id', $bundle->grantId)->firstOrFail();

    if ($failure === 'expired') {
        $raw = (string) Storage::disk('local')->get($export->path);
        [$headerLine, $payload] = explode("\n", $raw, 2);
        $header = json_decode($headerLine, true, flags: JSON_THROW_ON_ERROR);
        $header['manifest']['expires_at'] = now('UTC')->subMinute()->toIso8601String();
        Storage::disk('local')->put($export->path, CanonicalJson::encode($header)."\n".$payload);
    } elseif ($failure === 'target') {
        config([
            'bridge.instance.id' => 'different-destination-stage',
            'bridge.instance.name' => 'Different destination',
            'bridge.instance.role' => 'staging',
        ]);
    } else {
        becomeGenericBridgeDestination();
        Schema::table(GENERIC_BRIDGE_PARENT, fn (Blueprint $table) => $table->string('destination_only')->nullable());
    }

    app(BridgePackageInbox::class)->receiveFromProtectedPath($export->path, $grant);
})->with(['expired', 'target', 'schema'])->throws(BridgePackageException::class);

it('rejects revoked, expired, malformed, and incorrect receive keys before reading a body', function (): void {
    $manager = app(BridgeReceiveGrantManager::class);
    $bundle = issueGenericBridgeGrant();
    $grant = BridgeReceiveGrant::query()->where('grant_id', $bundle->grantId)->firstOrFail();
    $manager->revoke($grant);

    expect(fn () => $manager->authenticate($bundle->grantId, $bundle->secret))
        ->toThrow(BridgeTransportException::class, 'invalid, unavailable, or expired')
        ->and(fn () => BridgeReceiveGrantBundle::fromJson('{"not":"a grant"}'))
        ->toThrow(BridgeTransportException::class, 'malformed');

    $fresh = issueGenericBridgeGrant();
    expect(fn () => $manager->authenticate($fresh->grantId, str_repeat('a', 43)))
        ->toThrow(BridgeTransportException::class, 'invalid, unavailable, or expired');

    BridgeReceiveGrant::query()->where('grant_id', $fresh->grantId)->update(['expires_at' => now('UTC')->subSecond()]);
    expect(fn () => $manager->authenticate($fresh->grantId, $fresh->secret))
        ->toThrow(BridgeTransportException::class, 'invalid, unavailable, or expired');
});

it('rejects tampered bytes and packages above the configured receipt bound', function (): void {
    seedGenericBridgeFixture();
    ['export' => $export, 'grant' => $bundle] = exportGenericBridgeFixture();
    becomeGenericBridgeDestination();
    $grant = app(BridgeReceiveGrantManager::class)->authenticate($bundle->grantId, $bundle->secret);
    $original = (string) Storage::disk('local')->get($export->path);
    Storage::disk('local')->put($export->path, $original."{}\n");

    expect(fn () => app(BridgePackageInbox::class)->receiveFromProtectedPath($export->path, $grant))
        ->toThrow(BridgePackageException::class, 'unexpected bytes');

    Storage::disk('local')->put($export->path, $original);
    config(['bridge.transfer_limits.max_package_bytes' => $export->bytes - 1]);
    expect(fn () => app(BridgePackageInbox::class)->receiveFromProtectedPath($export->path, $grant))
        ->toThrow(BridgePackageException::class, 'exceeds');
});

it('streams a one-time authorized HTTP receipt without planning or applying', function (): void {
    seedGenericBridgeFixture();
    ['export' => $export, 'grant' => $bundle] = exportGenericBridgeFixture();
    $raw = (string) Storage::disk('local')->get($export->path);
    becomeGenericBridgeDestination();
    $path = (string) parse_url($bundle->endpoint, PHP_URL_PATH);

    $response = $this->call('POST', $path, server: [
        'CONTENT_LENGTH' => (string) strlen($raw),
        'CONTENT_TYPE' => 'application/x-ndjson',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$bundle->secret,
    ], content: $raw);

    $response->assertAccepted()
        ->assertJsonPath('package_id', $export->packageId)
        ->assertJsonPath('sha256', $export->sha256)
        ->assertJsonPath('grant_id', $bundle->grantId)
        ->assertJsonPath('status', 'verified');

    expect(BridgeReceipt::query()->count())->toBe(1)
        ->and(BridgePlan::query()->count())->toBe(0)
        ->and(BridgeReceiveGrant::query()->where('grant_id', $bundle->grantId)->value('status'))->toBe('consumed')
        ->and(DB::table(GENERIC_BRIDGE_PARENT)->where('id', 2)->exists())->toBeTrue();

    $this->call('POST', $path, server: [
        'CONTENT_LENGTH' => (string) strlen($raw),
        'CONTENT_TYPE' => 'application/x-ndjson',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$bundle->secret,
    ], content: $raw)->assertUnauthorized();
});

it('leaves a receive key usable when a streamed package is truncated before verification', function (): void {
    seedGenericBridgeFixture();
    ['export' => $export, 'grant' => $bundle] = exportGenericBridgeFixture();
    $raw = (string) Storage::disk('local')->get($export->path);
    $truncated = substr($raw, 0, -32);
    becomeGenericBridgeDestination();
    $path = (string) parse_url($bundle->endpoint, PHP_URL_PATH);
    $server = [
        'CONTENT_TYPE' => 'application/x-ndjson',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$bundle->secret,
    ];

    $this->call('POST', $path, server: [
        ...$server,
        'CONTENT_LENGTH' => (string) strlen($truncated),
    ], content: $truncated)->assertUnprocessable();

    expect(BridgeReceiveGrant::query()->where('grant_id', $bundle->grantId)->value('status'))->toBe('issued')
        ->and(BridgeReceipt::query()->count())->toBe(0);

    $this->call('POST', $path, server: [
        ...$server,
        'CONTENT_LENGTH' => (string) strlen($raw),
    ], content: $raw)->assertAccepted();

    expect(BridgeReceiveGrant::query()->where('grant_id', $bundle->grantId)->value('status'))->toBe('consumed')
        ->and(BridgeReceipt::query()->count())->toBe(1);
});

it('binds an admitted package hash to the consumed receive grant', function (): void {
    seedGenericBridgeFixture();
    $bundle = issueGenericBridgeGrant();
    ['export' => $accepted] = exportGenericBridgeFixture($bundle);
    ['export' => $other] = exportGenericBridgeFixture($bundle);
    $receipt = receiveGenericBridgePackage($bundle, $accepted);
    Storage::disk('local')->put($receipt->package_path, Storage::disk('local')->get($other->path));

    expect(fn () => app(BridgePackageVerifier::class)->verifyPath(
        $receipt->package_path,
        $receipt->grant()->firstOrFail(),
    ))->toThrow(BridgePackageException::class, 'consumed receive grant');
});

it('streams the protected Outgoing file with bearer auth and verifies the target receipt response', function (): void {
    seedGenericBridgeFixture();
    config(['bridge.receive_grants.base_urls' => [
        'https://livenpc.lan:8443',
        'https://bridge.example.test',
    ]]);
    ['export' => $export, 'grant' => $bundle] = exportGenericBridgeFixture();
    $selectedGrant = $bundle->usingEndpoint($bundle->endpoints[1]);
    $requestWasExact = false;
    Http::fake(function ($request) use ($selectedGrant, $export, &$requestWasExact) {
        $requestWasExact = $request->url() === $selectedGrant->endpoint
            && $request->hasHeader('Authorization', 'Bearer '.$selectedGrant->secret)
            && $request->hasHeader('Content-Type', 'application/x-ndjson')
            && $request->hasHeader('Content-Length', (string) $export->bytes)
            && strlen($request->body()) === $export->bytes;

        return Http::response([
            'package_id' => $export->packageId,
            'sha256' => $export->sha256,
            'grant_id' => $selectedGrant->grantId,
            'status' => 'verified',
        ], 202);
    });

    $result = app(BridgePackageSender::class)->send($export, $selectedGrant);

    expect($result['status'])->toBe('verified')
        ->and($requestWasExact)->toBeTrue();
});

it('prunes old applied Incoming files and abandoned uploads while retaining Outgoing by default', function (): void {
    seedGenericBridgeFixture();
    ['export' => $export, 'grant' => $grant] = exportGenericBridgeFixture();
    $receipt = receiveGenericBridgePackage($grant, $export);
    $receipt->forceFill(['status' => 'applied', 'received_at' => now('UTC')->subDays(15)])->save();
    $partial = 'bridge/receiving/https/abandoned.upload';
    Storage::disk('local')->put($partial, 'partial');
    touch(Storage::disk('local')->path($partial), now('UTC')->subDays(2)->timestamp);
    touch(Storage::disk('local')->path($export->path), now('UTC')->subDays(15)->timestamp);
    $retention = app(BridgePackageRetention::class);

    expect(array_column($retention->prune()['candidates'], 'category'))->toBe(['incoming', 'receiving']);
    $result = $retention->prune(commit: true);

    expect($result['deleted'])->toContain($receipt->package_path, $partial)
        ->and(Storage::disk('local')->exists($receipt->package_path))->toBeFalse()
        ->and(Storage::disk('local')->exists($export->path))->toBeTrue()
        ->and($receipt->refresh()->metadata['package_retained'])->toBeFalse();
});

it('requires an explicit retention override before deleting unapplied Incoming or Outgoing files', function (): void {
    seedGenericBridgeFixture();
    ['export' => $export, 'grant' => $grant] = exportGenericBridgeFixture();
    $receipt = receiveGenericBridgePackage($grant, $export);
    $receipt->forceFill(['received_at' => now('UTC')->subDays(15)])->save();
    touch(Storage::disk('local')->path($export->path), now('UTC')->subDays(15)->timestamp);
    $retention = app(BridgePackageRetention::class);

    expect($retention->prune(commit: true)['deleted'])->toBe([]);
    $result = $retention->prune(commit: true, includeUnapplied: true);

    expect($result['deleted'])->toContain($receipt->package_path, $export->path)
        ->and($receipt->refresh()->metadata['package_deletion_reason'])->toBe('unapplied-explicit');
});
