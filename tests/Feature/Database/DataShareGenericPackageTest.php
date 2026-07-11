<?php

use App\Base\Authz\Capability\CapabilityRegistry;
use App\Base\Database\DTO\DataShare\DataShareExportResult;
use App\Base\Database\DTO\DataShare\DataShareInstanceIdentity;
use App\Base\Database\DTO\DataShare\DataSharePackageExpectation;
use App\Base\Database\DTO\DataShare\DataShareTableDefinition;
use App\Base\Database\DTO\DataShare\DataShareTransferOfferBundle;
use App\Base\Database\Enums\DataShareInstanceRole;
use App\Base\Database\Exceptions\DataShareApplyException;
use App\Base\Database\Exceptions\DataShareDefinitionException;
use App\Base\Database\Exceptions\DataSharePackageException;
use App\Base\Database\Exceptions\DataSharePolicyException;
use App\Base\Database\Exceptions\DataShareTransportException;
use App\Base\Database\Livewire\DataShare\Index as DataShareIndex;
use App\Base\Database\Livewire\DataShare\Settings as DataShareSettingsPage;
use App\Base\Database\Models\DataShareEvent;
use App\Base\Database\Models\DataShareReceipt;
use App\Base\Database\Models\DataShareTransferOffer;
use App\Base\Database\Models\TableRegistry;
use App\Base\Database\Services\DataShare\DataShareDestinationMapper;
use App\Base\Database\Services\DataShare\DataShareImportPlanner;
use App\Base\Database\Services\DataShare\DataShareOfferFetcher;
use App\Base\Database\Services\DataShare\DataSharePackageApplier;
use App\Base\Database\Services\DataShare\DataSharePackageExporter;
use App\Base\Database\Services\DataShare\DataSharePackageInbox;
use App\Base\Database\Services\DataShare\DataSharePackageReader;
use App\Base\Database\Services\DataShare\DataSharePackageRetention;
use App\Base\Database\Services\DataShare\DataSharePackageVerifier;
use App\Base\Database\Services\DataShare\DataShareScopeCatalog;
use App\Base\Database\Services\DataShare\DataShareSettings;
use App\Base\Database\Services\DataShare\DataShareTransferOfferManager;
use App\Base\Database\Services\DataShare\DataShareValueNormalizer;
use App\Base\Settings\Contracts\SettingsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

const GENERIC_SHARE_SCOPE = 'tests/fixtures/data-share';
const GENERIC_SHARE_PARENT = 'test_data_share_parents';
const GENERIC_SHARE_CHILD = 'test_data_share_children';
const GENERIC_SHARE_SOURCE_NAME = 'Generic source';
const GENERIC_SHARE_BINARY_PAYLOAD = "\x00\xFFshare";
const GENERIC_SHARE_PRIMARY_URL = 'https://source.lan:8443';
const GENERIC_SHARE_FALLBACK_URL = 'https://share.example.test';

beforeEach(function (): void {
    Storage::fake('local');
    config([
        'app.env' => 'testing',
        'data_share.disk' => 'local',
        'data_share.instance.id' => 'generic-source-dev',
        'data_share.instance.name' => GENERIC_SHARE_SOURCE_NAME,
        'data_share.instance.role' => 'development',
        'data_share.outgoing_path_prefix' => 'data-share/outgoing',
        'data_share.incoming_path_prefix' => 'data-share/incoming',
        'data_share.receiving_path_prefix' => 'data-share/receiving',
        'data_share.offers.base_urls' => GENERIC_SHARE_PRIMARY_URL."\n".GENERIC_SHARE_FALLBACK_URL,
        'data_share.offers.expiry_minutes' => 60,
    ]);

    Schema::create(GENERIC_SHARE_PARENT, function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->string('code')->unique();
        $table->string('nullable_alias')->nullable()->unique();
        $table->string('name');
        $table->json('metadata')->nullable();
        $table->date('effective_on')->nullable();
        $table->decimal('amount', 16, 4);
        $table->binary('payload')->nullable();
    });
    Schema::create(GENERIC_SHARE_CHILD, function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('parent_id');
        $table->string('external_code')->unique();
        $table->text('note')->nullable();
        $table->foreign('parent_id')->references('id')->on(GENERIC_SHARE_PARENT);
    });
    TableRegistry::register(GENERIC_SHARE_PARENT, 'Data Share Fixture', GENERIC_SHARE_SCOPE, 'test');
    TableRegistry::register(GENERIC_SHARE_CHILD, 'Data Share Fixture', GENERIC_SHARE_SCOPE, 'test');
});

afterEach(function (): void {
    TableRegistry::unregister(GENERIC_SHARE_CHILD);
    TableRegistry::unregister(GENERIC_SHARE_PARENT);
    Schema::dropIfExists(GENERIC_SHARE_CHILD);
    Schema::dropIfExists(GENERIC_SHARE_PARENT);
});

function seedGenericDataShareFixture(): void
{
    DB::table(GENERIC_SHARE_PARENT)->insert([
        [
            'id' => 2,
            'code' => 'parent-2',
            'nullable_alias' => null,
            'name' => 'Éclair شركة',
            'metadata' => json_encode(['nested' => ['ready' => true]], JSON_THROW_ON_ERROR),
            'effective_on' => '2026-07-10',
            'amount' => '12.3400',
            'payload' => GENERIC_SHARE_BINARY_PAYLOAD,
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
    DB::table(GENERIC_SHARE_CHILD)->insert([
        'id' => 25,
        'parent_id' => 10,
        'external_code' => 'child-25',
        'note' => 'Relationship must keep the physical parent key.',
    ]);
}

function becomeGenericDataShareSource(): DataShareInstanceIdentity
{
    config([
        'data_share.instance.id' => 'generic-source-dev',
        'data_share.instance.name' => GENERIC_SHARE_SOURCE_NAME,
        'data_share.instance.role' => 'development',
    ]);

    return new DataShareInstanceIdentity('generic-source-dev', GENERIC_SHARE_SOURCE_NAME, DataShareInstanceRole::Development);
}

function becomeGenericDataShareDestination(bool $production = false): DataShareInstanceIdentity
{
    $role = $production ? DataShareInstanceRole::Production : DataShareInstanceRole::Staging;
    $id = $production ? 'generic-destination-production' : 'generic-destination-stage';

    config([
        'data_share.instance.id' => $id,
        'data_share.instance.name' => 'Generic destination',
        'data_share.instance.role' => $role->value,
    ]);

    return new DataShareInstanceIdentity($id, 'Generic destination', $role);
}

/** @return array{bundle: DataShareTransferOfferBundle, offer: DataShareTransferOffer, export: DataShareExportResult} */
function publishGenericDataShare(
    array $tables = [GENERIC_SHARE_PARENT, GENERIC_SHARE_CHILD],
): array {
    becomeGenericDataShareSource();
    $exporter = app(DataSharePackageExporter::class);
    $preview = $exporter->preview(GENERIC_SHARE_SCOPE, $tables);
    $bundle = app(DataShareTransferOfferManager::class)->publish(
        GENERIC_SHARE_SCOPE,
        $tables,
        $preview->previewHash,
        actorId: 9001,
    );
    $offer = DataShareTransferOffer::query()->where('offer_id', $bundle->offerId)->firstOrFail();
    $stream = Storage::disk('local')->readStream($offer->package_path);

    try {
        $manifest = app(DataSharePackageReader::class)->manifest($stream);
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    return [
        'bundle' => $bundle,
        'offer' => $offer,
        'export' => new DataShareExportResult(
            $offer->package_id,
            $offer->package_path,
            $offer->package_sha256,
            $offer->bytes,
            $manifest,
        ),
    ];
}

function receiveGenericDataShare(DataShareTransferOfferBundle $bundle, DataShareExportResult $export, bool $production = false): DataShareReceipt
{
    becomeGenericDataShareDestination($production);

    return app(DataSharePackageInbox::class)->receiveFromProtectedPath(
        $export->path,
        DataSharePackageExpectation::fromOffer($bundle),
    );
}

it('discovers a module scope and relational contract without module-specific share code', function (): void {
    $scope = app(DataShareScopeCatalog::class)->scope(GENERIC_SHARE_SCOPE);

    expect(array_column($scope->tables, 'table'))->toBe([GENERIC_SHARE_PARENT, GENERIC_SHARE_CHILD])
        ->and($scope->tables[0]->primaryKeyColumns)->toBe(['id'])
        ->and($scope->tables[1]->references)->toHaveCount(1)
        ->and($scope->tables[1]->references[0]->targetTable)->toBe(GENERIC_SHARE_PARENT);
});

it('rejects a selected foreign-key cycle that has no generic insert order', function (): void {
    $first = 'test_data_share_cycle_first';
    $second = 'test_data_share_cycle_second';
    $scope = 'tests/fixtures/data-share-cycle';
    Schema::disableForeignKeyConstraints();

    try {
        DB::statement("CREATE TABLE {$first} (id INTEGER PRIMARY KEY, second_id INTEGER NOT NULL, FOREIGN KEY(second_id) REFERENCES {$second}(id))");
        DB::statement("CREATE TABLE {$second} (id INTEGER PRIMARY KEY, first_id INTEGER NOT NULL, FOREIGN KEY(first_id) REFERENCES {$first}(id))");
        TableRegistry::register($first, 'Data Share Cycle Fixture', $scope, 'test');
        TableRegistry::register($second, 'Data Share Cycle Fixture', $scope, 'test');

        expect(fn () => app(DataShareScopeCatalog::class)->scope($scope))
            ->toThrow(DataShareDefinitionException::class, 'foreign-key cycle');
    } finally {
        TableRegistry::unregister($second);
        TableRegistry::unregister($first);
        Schema::dropIfExists($second);
        Schema::dropIfExists($first);
        Schema::enableForeignKeyConstraints();
    }
});

it('publishes a target-neutral immutable offer while persisting only the secret hash', function (): void {
    seedGenericDataShareFixture();
    ['bundle' => $bundle, 'offer' => $offer, 'export' => $export] = publishGenericDataShare();
    $decoded = DataShareTransferOfferBundle::fromJson($bundle->toJson());

    expect($bundle->secret)->toHaveLength(43)
        ->and($offer->secret_hash)->toBe(hash('sha256', $bundle->secret))
        ->and($offer->getRawOriginal('secret'))->toBeNull()
        ->and($decoded->offerId)->toBe($offer->offer_id)
        ->and($decoded->packageSha256)->toBe($export->sha256)
        ->and($decoded->endpoints)->toBe([
            GENERIC_SHARE_PRIMARY_URL.'/data-share/offers/'.$offer->offer_id,
            GENERIC_SHARE_FALLBACK_URL.'/data-share/offers/'.$offer->offer_id,
        ])
        ->and($export->manifest)->not->toHaveKey('target')
        ->and($export->manifest)->not->toHaveKey('receive_grant_id')
        ->and($export->manifest['transfer_offer_id'])->toBe($offer->offer_id)
        ->and(DataShareEvent::query()->where('action', 'offer_published')->count())->toBe(1);
});

it('validates offer bundles and permits only an advertised route', function (): void {
    seedGenericDataShareFixture();
    ['bundle' => $bundle] = publishGenericDataShare();

    expect($bundle->usingEndpoint($bundle->endpoints[1])->endpoint)->toBe($bundle->endpoints[1])
        ->and(fn () => $bundle->usingEndpoint('https://other.example.test/data-share/offers/'.$bundle->offerId))
        ->toThrow(DataShareTransportException::class)
        ->and(fn () => DataShareTransferOfferBundle::fromJson('{"not":"an offer"}'))
        ->toThrow(DataShareTransportException::class);
});

it('refuses offer metadata that does not describe the immutable package', function (): void {
    seedGenericDataShareFixture();
    ['bundle' => $bundle, 'export' => $export] = publishGenericDataShare();
    $value = json_decode($bundle->toJson(), true, flags: JSON_THROW_ON_ERROR);
    $value['counts']['records']++;
    $tampered = DataShareTransferOfferBundle::fromJson(json_encode($value, JSON_THROW_ON_ERROR));
    becomeGenericDataShareDestination();

    expect(fn () => app(DataSharePackageVerifier::class)->verifyPath(
        $export->path,
        DataSharePackageExpectation::fromOffer($tampered),
    ))->toThrow(DataSharePackageException::class, 'does not match its transfer offer');
});

it('streams the same immutable source bytes repeatedly until revocation', function (): void {
    seedGenericDataShareFixture();
    ['bundle' => $bundle, 'offer' => $offer, 'export' => $export] = publishGenericDataShare();
    $headers = ['Authorization' => 'Bearer '.$bundle->secret, 'Accept' => 'application/x-ndjson'];

    $first = $this->withHeaders($headers)->get('/data-share/offers/'.$bundle->offerId);
    $second = $this->withHeaders($headers)->get('/data-share/offers/'.$bundle->offerId);
    ob_start();
    $first->sendContent();
    $firstContent = (string) ob_get_clean();
    ob_start();
    $second->sendContent();
    $secondContent = (string) ob_get_clean();

    expect($first->getStatusCode())->toBe(200)
        ->and($first->headers->get('X-Data-Share-Package-Sha256'))->toBe($export->sha256)
        ->and(hash('sha256', $firstContent))->toBe($export->sha256)
        ->and(hash('sha256', $secondContent))->toBe($export->sha256)
        ->and($offer->refresh()->download_count)->toBe(2);

    app(DataShareTransferOfferManager::class)->revoke($offer);
    expect($this->withHeaders($headers)->get('/data-share/offers/'.$bundle->offerId)->getStatusCode())->toBe(401);
});

it('refuses incorrect and expired offer secrets without exposing package bytes', function (): void {
    seedGenericDataShareFixture();
    ['bundle' => $bundle, 'offer' => $offer] = publishGenericDataShare();

    expect($this->withToken(str_repeat('x', 43))->getJson('/data-share/offers/'.$bundle->offerId)->getStatusCode())->toBe(401);
    $offer->forceFill(['expires_at' => now('UTC')->subSecond()])->save();
    expect($this->withToken($bundle->secret)->getJson('/data-share/offers/'.$bundle->offerId)->getStatusCode())->toBe(401);

    expect($offer->refresh()->status)->toBe('expired');
});

it('admits repeated identical fetches as one target-bound Incoming receipt', function (): void {
    seedGenericDataShareFixture();
    ['bundle' => $bundle, 'export' => $export] = publishGenericDataShare();
    $first = receiveGenericDataShare($bundle, $export);
    $second = receiveGenericDataShare($bundle, $export);

    expect($second->id)->toBe($first->id)
        ->and(DataShareReceipt::query()->count())->toBe(1);

    becomeGenericDataShareDestination(production: true);
    expect(fn () => app(DataSharePackageInbox::class)->receiveFromProtectedPath(
        $export->path,
        DataSharePackageExpectation::fromOffer($bundle),
    ))->toThrow(DataSharePackageException::class, 'different offer, source, scope, target, or byte sequence');
});

it('uses only CapabilityCatalog-recognized Data Share actions', function (): void {
    $capabilities = app(CapabilityRegistry::class)->all();

    expect($capabilities)->toContain(
        'admin.system.data-share-offer.create',
        'admin.system.data-share-offer.accept',
        'admin.system.data-share-offer.manage',
    );
});

it('publishes from Share and reviews an offer from Incoming without fetching or applying', function (): void {
    seedGenericDataShareFixture();
    $this->actingAs(createAdminUser());

    $source = Livewire::test(DataShareIndex::class)
        ->set('scopeName', GENERIC_SHARE_SCOPE)
        ->call('previewShare')
        ->assertSet('statusVariant', 'success')
        ->call('publishShare')
        ->assertSet('statusVariant', 'success');
    $bundle = DataShareTransferOfferBundle::fromJson($source->get('publishedOfferBundle'));
    $source->call('clearPublishedOfferBundle')->assertSet('publishedOfferBundle', null);

    becomeGenericDataShareDestination();
    Livewire::test(DataShareIndex::class)
        ->set('offerBundle', $bundle->toJson())
        ->call('reviewOffer')
        ->assertSet('statusVariant', 'success')
        ->assertSet('offerEndpoint', $bundle->endpoint)
        ->assertSet('reviewedOffer.offer_id', $bundle->offerId)
        ->assertSet('reviewedOffer.scope', GENERIC_SHARE_SCOPE);

    expect(DataShareReceipt::query()->count())->toBe(0);
});

it('explains the publish and pull workflow and orients Data Share settings', function (): void {
    $this->actingAs(createAdminUser());

    $this->get(route('admin.system.data-share.index'))
        ->assertOk()
        ->assertSee('How Data Share works')
        ->assertSee('Publish on the source.')
        ->assertSee('Fetch and verify.')
        ->assertSee('Apply and verify.');
    $this->get(route('admin.system.data-share.settings'))
        ->assertOk()
        ->assertSee('About Data Share settings')
        ->assertSee('The source publishes an expiring offer');
});

it('stores Data Share operator configuration in Base Settings and validates source routes', function (): void {
    $this->actingAs(createAdminUser());
    $component = Livewire::test(DataShareSettingsPage::class)
        ->assertSet('values.data_share__instance__id', 'generic-source-dev')
        ->set('values.data_share__instance__id', 'settings-source')
        ->set('values.data_share__instance__name', 'Settings source')
        ->set('values.data_share__offers__base_urls', "https://settings-source.internal\nhttps://settings-source.example.test")
        ->call('save')
        ->assertHasNoErrors();

    expect(app(SettingsService::class)->get('data_share.instance.id'))->toBe('settings-source')
        ->and(app(DataShareSettings::class)->stringList('data_share.offers.base_urls'))->toBe([
            'https://settings-source.internal',
            'https://settings-source.example.test',
        ]);

    Livewire::test(DataShareSettingsPage::class)
        ->set('values.data_share__offers__base_urls', 'http://source.example.test?token=bad')
        ->call('save')
        ->assertHasErrors(['values.data_share__offers__base_urls']);
});

it('resolves each Base Setting only once per Data Share service instance', function (): void {
    $service = Mockery::mock(SettingsService::class);
    $service->shouldReceive('get')->once()->with('data_share.transfer_limits.max_records', 250000)->andReturn('42');
    $settings = new DataShareSettings($service);

    expect($settings->integer('data_share.transfer_limits.max_records', 250000, 1, 10000000))->toBe(42)
        ->and($settings->integer('data_share.transfer_limits.max_records', 250000, 1, 10000000))->toBe(42);
});

it('exports deterministic bounded payloads with physical identities and binary fidelity', function (): void {
    seedGenericDataShareFixture();
    becomeGenericDataShareSource();
    $exporter = app(DataSharePackageExporter::class);
    $first = $exporter->preview(GENERIC_SHARE_SCOPE, [GENERIC_SHARE_PARENT, GENERIC_SHARE_CHILD]);
    $second = $exporter->preview(GENERIC_SHARE_SCOPE, [GENERIC_SHARE_PARENT, GENERIC_SHARE_CHILD]);
    ['export' => $export] = publishGenericDataShare();
    $stream = Storage::disk('local')->readStream($export->path);
    $rows = [];

    try {
        $verified = app(DataSharePackageReader::class)->inspect(
            $stream,
            function ($scope, $table, array $record) use (&$rows): void {
                $rows[$table->table][] = $record;
            },
        );
    } finally {
        fclose($stream);
    }

    expect($first->previewHash)->toBe($second->previewHash)
        ->and($verified->sha256)->toBe($export->sha256)
        ->and(array_column($rows[GENERIC_SHARE_PARENT], 'primary_key'))->toBe([['id' => 2], ['id' => 10]])
        ->and($rows[GENERIC_SHARE_PARENT][0]['values']['payload'])->toBe([
            '__data_share_binary_base64' => base64_encode(GENERIC_SHARE_BINARY_PAYLOAD),
        ]);
});

it('enforces scalar, canonical-line, record, and table bounds before publishing', function (array $limits, string $message): void {
    seedGenericDataShareFixture();
    config($limits);

    expect(fn () => app(DataSharePackageExporter::class)->preview(
        GENERIC_SHARE_SCOPE,
        [GENERIC_SHARE_PARENT, GENERIC_SHARE_CHILD],
    ))->toThrow(DataSharePackageException::class, $message);
})->with([
    'scalar' => [['data_share.transfer_limits.max_scalar_bytes' => 4], 'scalar'],
    'canonical line' => [['data_share.transfer_limits.max_record_line_bytes' => 128], 'line limit'],
    'records' => [['data_share.transfer_limits.max_records' => 1], 'record limit'],
    'tables' => [['data_share.transfer_limits.max_tables' => 1], 'table limit'],
]);

it('plans and applies inserts, preserves relationships, rejects replay, and replans unchanged', function (): void {
    seedGenericDataShareFixture();
    ['bundle' => $bundle, 'export' => $export] = publishGenericDataShare();
    DB::table(GENERIC_SHARE_CHILD)->delete();
    DB::table(GENERIC_SHARE_PARENT)->delete();
    $receipt = receiveGenericDataShare($bundle, $export);
    $plan = app(DataShareImportPlanner::class)->plan($receipt);

    expect($plan->status)->toBe('ready')
        ->and($plan->summary['counts'])->toBe(['insert' => 3, 'unchanged' => 0, 'conflict' => 0]);

    app(DataSharePackageApplier::class)->apply($plan, $receipt->package_sha256, $plan->plan_hash, confirmed: true);

    expect((int) DB::table(GENERIC_SHARE_CHILD)->value('parent_id'))->toBe(10)
        ->and(DB::table(GENERIC_SHARE_PARENT)->where('id', 2)->value('payload'))->toBe(GENERIC_SHARE_BINARY_PAYLOAD)
        ->and(DataShareEvent::query()->pluck('action')->all())->toContain('offer_published', 'received', 'planned', 'applied');

    expect(fn () => app(DataSharePackageApplier::class)->apply(
        $plan->refresh(),
        $receipt->package_sha256,
        $plan->plan_hash,
        confirmed: true,
    ))->toThrow(DataShareApplyException::class, 'already been applied');

    ['bundle' => $repeatBundle, 'export' => $repeatExport] = publishGenericDataShare();
    $repeatPlan = app(DataShareImportPlanner::class)->plan(receiveGenericDataShare($repeatBundle, $repeatExport));
    expect($repeatPlan->summary['counts'])->toBe(['insert' => 0, 'unchanged' => 3, 'conflict' => 0]);
});

it('refuses apply while the global Data Share lock is held', function (): void {
    seedGenericDataShareFixture();
    ['bundle' => $bundle, 'export' => $export] = publishGenericDataShare();
    $receipt = receiveGenericDataShare($bundle, $export);
    $plan = app(DataShareImportPlanner::class)->plan($receipt);
    $lock = Cache::lock('base:data-share:apply', 900);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => app(DataSharePackageApplier::class)->apply(
            $plan,
            $receipt->package_sha256,
            $plan->plan_hash,
            confirmed: true,
        ))->toThrow(DataShareApplyException::class, 'already running');
    } finally {
        $lock->release();
    }
});

it('blocks production apply before mutation when recovery cannot be created', function (): void {
    seedGenericDataShareFixture();
    ['bundle' => $bundle, 'export' => $export] = publishGenericDataShare();
    DB::table(GENERIC_SHARE_CHILD)->delete();
    DB::table(GENERIC_SHARE_PARENT)->delete();
    $receipt = receiveGenericDataShare($bundle, $export, production: true);
    $plan = app(DataShareImportPlanner::class)->plan($receipt);
    config(['backup.enabled' => false]);

    expect(fn () => app(DataSharePackageApplier::class)->apply(
        $plan,
        $receipt->package_sha256,
        $plan->plan_hash,
        confirmed: true,
    ))->toThrow(DataShareApplyException::class, 'fresh verified backup');

    expect(DB::table(GENERIC_SHARE_PARENT)->count())->toBe(0)
        ->and($plan->refresh()->status)->toBe('ready');
});

it('rolls back a partial apply and succeeds on a clean retry', function (): void {
    seedGenericDataShareFixture();
    ['bundle' => $bundle, 'export' => $export] = publishGenericDataShare();
    DB::table(GENERIC_SHARE_CHILD)->delete();
    DB::table(GENERIC_SHARE_PARENT)->delete();
    $receipt = receiveGenericDataShare($bundle, $export);
    $plan = app(DataShareImportPlanner::class)->plan($receipt);
    $failing = new class(app(DataShareValueNormalizer::class), app(DataShareScopeCatalog::class)) extends DataShareDestinationMapper
    {
        private int $calls = 0;

        public function findExisting(DataShareTableDefinition $table, array $record): ?array
        {
            if (++$this->calls === 6) {
                throw new RuntimeException('Injected Data Share transaction failure.');
            }

            return parent::findExisting($table, $record);
        }
    };
    app()->instance(DataShareDestinationMapper::class, $failing);

    expect(fn () => app(DataSharePackageApplier::class)->apply(
        $plan,
        $receipt->package_sha256,
        $plan->plan_hash,
        confirmed: true,
    ))->toThrow(RuntimeException::class, 'Injected Data Share transaction failure');
    expect(DB::table(GENERIC_SHARE_PARENT)->count())->toBe(0);

    app()->instance(DataShareDestinationMapper::class, new DataShareDestinationMapper(
        app(DataShareValueNormalizer::class),
        app(DataShareScopeCatalog::class),
    ));
    $result = app(DataSharePackageApplier::class)->apply(
        $plan->refresh(),
        $receipt->package_sha256,
        $plan->plan_hash,
        confirmed: true,
    );
    expect($result->counts['insert'])->toBe(3);
});

it('blocks primary-key divergence, unique collisions, and missing parents', function (): void {
    seedGenericDataShareFixture();
    ['bundle' => $bundle, 'export' => $export] = publishGenericDataShare();
    DB::table(GENERIC_SHARE_CHILD)->delete();
    DB::table(GENERIC_SHARE_PARENT)->where('id', 2)->update(['name' => 'Destination changed']);
    DB::table(GENERIC_SHARE_PARENT)->where('id', 10)->delete();
    DB::table(GENERIC_SHARE_PARENT)->insert([
        'id' => 99,
        'code' => 'parent-10',
        'nullable_alias' => null,
        'name' => 'Unique collision',
        'metadata' => null,
        'effective_on' => null,
        'amount' => '1.0000',
        'payload' => null,
    ]);
    $plan = app(DataShareImportPlanner::class)->plan(receiveGenericDataShare($bundle, $export));

    expect($plan->status)->toBe('conflicts')
        ->and($plan->summary['counts']['conflict'])->toBe(3);
});

it('allows nullable unique values and produces stable plans', function (): void {
    seedGenericDataShareFixture();
    ['bundle' => $bundle, 'export' => $export] = publishGenericDataShare([GENERIC_SHARE_PARENT]);
    DB::table(GENERIC_SHARE_CHILD)->delete();
    DB::table(GENERIC_SHARE_PARENT)->delete();
    DB::table(GENERIC_SHARE_PARENT)->insert([
        'id' => 99,
        'code' => 'destination-only',
        'nullable_alias' => null,
        'name' => 'Destination only',
        'metadata' => null,
        'effective_on' => null,
        'amount' => '1.0000',
        'payload' => null,
    ]);
    $receipt = receiveGenericDataShare($bundle, $export);
    $first = app(DataShareImportPlanner::class)->plan($receipt);
    $second = app(DataShareImportPlanner::class)->plan($receipt->refresh());

    expect($first->summary['counts'])->toBe(['insert' => 2, 'unchanged' => 0, 'conflict' => 0])
        ->and($first->plan_hash)->toBe($second->plan_hash);
});

it('invalidates a reviewed plan when destination data changes', function (): void {
    seedGenericDataShareFixture();
    ['bundle' => $bundle, 'export' => $export] = publishGenericDataShare();
    $receipt = receiveGenericDataShare($bundle, $export);
    $plan = app(DataShareImportPlanner::class)->plan($receipt);
    DB::table(GENERIC_SHARE_PARENT)->where('id', 2)->update(['name' => 'Changed after planning']);

    app(DataSharePackageApplier::class)->apply(
        $plan,
        $receipt->package_sha256,
        $plan->plan_hash,
        confirmed: true,
    );
})->throws(DataShareApplyException::class, 'Destination data changed after preview');

it('rejects a lateral target, expired package, schema drift, and tampered bytes', function (string $failure): void {
    seedGenericDataShareFixture();
    ['bundle' => $bundle, 'export' => $export] = publishGenericDataShare();

    if ($failure === 'lateral') {
        becomeGenericDataShareSource();
        expect(fn () => app(DataSharePackageInbox::class)->receiveFromProtectedPath(
            $export->path,
            DataSharePackageExpectation::fromOffer($bundle),
        ))->toThrow(DataSharePolicyException::class, 'denied');

        return;
    }

    becomeGenericDataShareDestination();

    if ($failure === 'expired') {
        $this->travel(61)->minutes();
    } elseif ($failure === 'schema') {
        Schema::table(GENERIC_SHARE_PARENT, fn (Blueprint $table) => $table->string('destination_only')->nullable());
    } else {
        $contents = Storage::disk('local')->get($export->path);
        Storage::disk('local')->put($export->path, substr($contents, 0, -2).'x\n');
    }

    expect(fn () => app(DataSharePackageVerifier::class)->verifyPath(
        $export->path,
        DataSharePackageExpectation::fromOffer($bundle),
    ))->toThrow($failure === 'lateral' ? DataSharePolicyException::class : DataSharePackageException::class);
})->with(['lateral', 'expired', 'schema', 'tampered']);

it('rejects an offer locally before HTTP when direction is not allowed', function (): void {
    seedGenericDataShareFixture();
    ['bundle' => $bundle] = publishGenericDataShare();
    becomeGenericDataShareSource();
    Http::fake();

    expect(fn () => app(DataShareOfferFetcher::class)->fetch($bundle))
        ->toThrow(DataSharePolicyException::class, 'denied');
    Http::assertNothingSent();
});

it('fetches an advertised offer into bounded target Incoming without planning', function (): void {
    seedGenericDataShareFixture();
    ['bundle' => $bundle, 'export' => $export] = publishGenericDataShare();
    $bytes = Storage::disk('local')->get($export->path);
    becomeGenericDataShareDestination();
    Http::fake([
        $bundle->endpoint => Http::response($bytes, 200, [
            'Content-Type' => 'application/x-ndjson',
            'Content-Length' => (string) strlen($bytes),
            'X-Data-Share-Offer-Id' => $bundle->offerId,
            'X-Data-Share-Package-Id' => $bundle->packageId,
            'X-Data-Share-Package-Sha256' => $bundle->packageSha256,
        ]),
    ]);

    $receipt = app(DataShareOfferFetcher::class)->fetch($bundle);

    expect($receipt->package_id)->toBe($bundle->packageId)
        ->and($receipt->status)->toBe('verified')
        ->and($receipt->plans)->toHaveCount(0)
        ->and(Storage::disk('local')->exists($receipt->package_path))->toBeTrue();
});

it('deletes a fetched temporary stream when response metadata is wrong', function (): void {
    seedGenericDataShareFixture();
    ['bundle' => $bundle, 'export' => $export] = publishGenericDataShare();
    $bytes = Storage::disk('local')->get($export->path);
    becomeGenericDataShareDestination();
    Http::fake([
        $bundle->endpoint => Http::response($bytes, 200, [
            'Content-Type' => 'application/x-ndjson',
            'Content-Length' => (string) strlen($bytes),
            'X-Data-Share-Offer-Id' => $bundle->offerId,
            'X-Data-Share-Package-Id' => $bundle->packageId,
            'X-Data-Share-Package-Sha256' => str_repeat('0', 64),
        ]),
    ]);

    expect(fn () => app(DataShareOfferFetcher::class)->fetch($bundle))
        ->toThrow(DataShareTransportException::class, 'metadata');
    expect(DataShareReceipt::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('data-share/receiving'))->toBe([]);
});

it('does not prune an available published offer and requires explicit outgoing cleanup', function (): void {
    seedGenericDataShareFixture();
    ['bundle' => $bundle, 'offer' => $offer, 'export' => $export] = publishGenericDataShare();
    $receipt = receiveGenericDataShare($bundle, $export);
    $receipt->forceFill(['status' => 'applied', 'received_at' => now('UTC')->subDays(30)])->save();
    config(['data_share.transfer_limits.incoming_retention_days' => 14]);
    touch(Storage::disk('local')->path($offer->package_path), now('UTC')->subDays(30)->timestamp);

    $default = app(DataSharePackageRetention::class)->prune();
    expect(array_column($default['candidates'], 'path'))->toContain($receipt->package_path)
        ->not->toContain($offer->package_path);

    $explicit = app(DataSharePackageRetention::class)->prune(includeUnapplied: true);
    expect(array_column($explicit['candidates'], 'path'))->not->toContain($offer->package_path);

    $offer->forceFill(['status' => 'revoked', 'revoked_at' => now('UTC')])->save();
    $revoked = app(DataSharePackageRetention::class)->prune(includeUnapplied: true);
    expect(array_column($revoked['candidates'], 'path'))->toContain($offer->package_path);
});
