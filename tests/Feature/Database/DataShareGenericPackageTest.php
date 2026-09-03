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
use App\Base\Database\Models\DataSharePlan;
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
use App\Base\Database\Services\DataShare\DataShareRedactionAdvisor;
use App\Base\Database\Services\DataShare\DataShareScopeCatalog;
use App\Base\Database\Services\DataShare\DataShareSettings;
use App\Base\Database\Services\DataShare\DataShareTransferOfferManager;
use App\Base\Database\Services\DataShare\DataShareValueNormalizer;
use App\Base\Settings\Contracts\SettingsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
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
const GENERIC_SHARE_OFFER_PATH = '/data-share/offers/';
const GENERIC_SHARE_NDJSON = 'application/x-ndjson';
const GENERIC_SHARE_RECEIVING_PATH = 'data-share/receiving';
const GENERIC_SHARE_DESTINATION_NAME = 'Generic destination';

beforeEach(function (): void {
    Storage::fake('local');
    config(['app.env' => 'testing']);
    setGenericDataShareSettings([
        'data_share.disk' => 'local',
        'data_share.instance.id' => 'generic-source-dev',
        'data_share.instance.name' => GENERIC_SHARE_SOURCE_NAME,
        'data_share.instance.role' => 'development',
        'data_share.outgoing_path_prefix' => 'data-share/outgoing',
        'data_share.incoming_path_prefix' => 'data-share/incoming',
        'data_share.receiving_path_prefix' => GENERIC_SHARE_RECEIVING_PATH,
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

/** @param array<string, mixed> $values */
function setGenericDataShareSettings(array $values): void
{
    $settings = app(SettingsService::class);

    foreach ($values as $key => $value) {
        $settings->set($key, $value);
    }
}

afterEach(function (): void {
    TableRegistry::unregister(GENERIC_SHARE_CHILD);
    TableRegistry::unregister(GENERIC_SHARE_PARENT);
    Schema::dropIfExists(GENERIC_SHARE_CHILD);
    Schema::dropIfExists(GENERIC_SHARE_PARENT);
});

/** Bind binary as a stream (PDO::PARAM_LOB): a plain string is truncated at NUL by PostgreSQL. */
function genericShareBinaryStream(string $bytes): mixed
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $bytes);
    rewind($stream);

    return $stream;
}

/** PDO pgsql returns bytea as a stream; SQLite as a string. Compare bytes, not handles. */
function genericShareBinary(mixed $value): ?string
{
    return is_resource($value) ? (string) stream_get_contents($value) : $value;
}

/**
 * Two tables that reference each other. Both must exist before either foreign
 * key is declared: PostgreSQL rejects a forward reference even with constraint
 * checking deferred, and the failure would poison the test transaction.
 */
function genericShareCreateCycle(string $first, string $second): void
{
    Schema::create($first, function (Blueprint $table): void {
        $table->integer('id')->primary();
        $table->integer('second_id');
    });
    Schema::create($second, function (Blueprint $table): void {
        $table->integer('id')->primary();
        $table->integer('first_id');
    });
    Schema::table($first, fn (Blueprint $table) => $table->foreign('second_id')->references('id')->on($second));
    Schema::table($second, fn (Blueprint $table) => $table->foreign('first_id')->references('id')->on($first));
}

/** Drop a referencing pair: PostgreSQL needs CASCADE for a table another one still references. */
function genericShareDropCycle(string $first, string $second): void
{
    if (DB::connection()->getDriverName() === 'pgsql') {
        DB::statement("DROP TABLE IF EXISTS \"{$second}\" CASCADE");
        DB::statement("DROP TABLE IF EXISTS \"{$first}\" CASCADE");

        return;
    }

    Schema::dropIfExists($second);
    Schema::dropIfExists($first);
}

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
            'payload' => genericShareBinaryStream(GENERIC_SHARE_BINARY_PAYLOAD),
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
    setGenericDataShareSettings([
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

    setGenericDataShareSettings([
        'data_share.instance.id' => $id,
        'data_share.instance.name' => GENERIC_SHARE_DESTINATION_NAME,
        'data_share.instance.role' => $role->value,
    ]);

    return new DataShareInstanceIdentity($id, GENERIC_SHARE_DESTINATION_NAME, $role);
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
        genericShareCreateCycle($first, $second);
        TableRegistry::register($first, 'Data Share Cycle Fixture', $scope, 'test');
        TableRegistry::register($second, 'Data Share Cycle Fixture', $scope, 'test');

        expect(fn () => app(DataShareScopeCatalog::class)->scope($scope))
            ->toThrow(DataShareDefinitionException::class, 'foreign-key cycle');
    } finally {
        TableRegistry::unregister($second);
        TableRegistry::unregister($first);
        genericShareDropCycle($first, $second);
        Schema::enableForeignKeyConstraints();
    }
});

it('keeps the Data Share page available when one registered scope has a foreign-key cycle', function (): void {
    $first = 'test_data_share_page_cycle_first';
    $second = 'test_data_share_page_cycle_second';
    $scope = 'tests/fixtures/data-share-page-cycle';
    Schema::disableForeignKeyConstraints();

    try {
        genericShareCreateCycle($first, $second);
        TableRegistry::register($first, 'Data Share Page Cycle Fixture', $scope, 'test');
        TableRegistry::register($second, 'Data Share Page Cycle Fixture', $scope, 'test');
        $this->actingAs(createAdminUser());

        expect(Artisan::call('blb:db:share:scopes'))->toBe(1)
            ->and(Artisan::output())->toContain('foreign-key cycle');

        $component = Livewire::test(DataShareIndex::class)
            ->assertSee('Some table scopes are unavailable for sharing:')
            ->assertSee('Data Share Page Cycle Fixture')
            ->assertSee('Other scopes and the development mirror remain available.');

        expect(array_column($component->get('scopes'), 'name'))
            ->toContain(GENERIC_SHARE_SCOPE)
            ->not->toContain($scope);
        expect(array_column($component->get('scopeIssues'), 'name'))->toContain($scope);
    } finally {
        TableRegistry::unregister($second);
        TableRegistry::unregister($first);
        genericShareDropCycle($first, $second);
        Schema::enableForeignKeyConstraints();
    }
});

it('publishes a target-neutral immutable offer and persists a copyable encrypted secret', function (): void {
    seedGenericDataShareFixture();
    ['bundle' => $bundle, 'offer' => $offer, 'export' => $export] = publishGenericDataShare();
    $decoded = DataShareTransferOfferBundle::fromJson($bundle->toJson());

    expect($bundle->secret)->toHaveLength(43)
        ->and($offer->secret_hash)->toBe(hash('sha256', $bundle->secret))
        ->and($offer->secret)->toBe($bundle->secret)
        ->and($offer->getRawOriginal('secret'))->not->toBe($bundle->secret)
        ->and($decoded->offerId)->toBe($offer->offer_id)
        ->and($decoded->packageSha256)->toBe($export->sha256)
        ->and($decoded->endpoints)->toBe([
            GENERIC_SHARE_PRIMARY_URL.GENERIC_SHARE_OFFER_PATH.$offer->offer_id,
            GENERIC_SHARE_FALLBACK_URL.GENERIC_SHARE_OFFER_PATH.$offer->offer_id,
        ])
        ->and($export->manifest)->not->toHaveKey('target')
        ->and($export->manifest)->not->toHaveKey('receive_grant_id')
        ->and($export->manifest['transfer_offer_id'])->toBe($offer->offer_id)
        ->and(DataShareEvent::query()->where('action', 'offer_published')->count())->toBe(1);

    becomeGenericDataShareDestination();
    $recopied = app(DataShareTransferOfferManager::class)->bundleFor($offer);

    expect($recopied->toJson())->toBe($bundle->toJson())
        ->and($recopied->source->name)->toBe(GENERIC_SHARE_SOURCE_NAME);
});

it('rejects invalid fetch limits before exporting a package', function (): void {
    expect(fn () => app(DataShareTransferOfferManager::class)->publish(
        GENERIC_SHARE_SCOPE,
        [GENERIC_SHARE_PARENT],
        str_repeat('0', 64),
        maxDownloads: DataShareTransferOfferManager::MAX_DOWNLOADS + 1,
    ))->toThrow(DataSharePolicyException::class, 'maximum fetches');
});

it('validates offer bundles and permits only an advertised route', function (): void {
    seedGenericDataShareFixture();
    ['bundle' => $bundle] = publishGenericDataShare();

    expect($bundle->usingEndpoint($bundle->endpoints[1])->endpoint)->toBe($bundle->endpoints[1])
        ->and(fn () => $bundle->usingEndpoint('https://other.example.test'.GENERIC_SHARE_OFFER_PATH.$bundle->offerId))
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
    $headers = ['Authorization' => 'Bearer '.$bundle->secret, 'Accept' => GENERIC_SHARE_NDJSON];

    $first = $this->withHeaders($headers)->get(GENERIC_SHARE_OFFER_PATH.$bundle->offerId);
    $second = $this->withHeaders($headers)->get(GENERIC_SHARE_OFFER_PATH.$bundle->offerId);
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
    expect($this->withHeaders($headers)->get(GENERIC_SHARE_OFFER_PATH.$bundle->offerId)->getStatusCode())->toBe(401);
});

it('closes a single-use offer when the first fetch is claimed', function (): void {
    seedGenericDataShareFixture();
    becomeGenericDataShareSource();
    $tables = [GENERIC_SHARE_PARENT, GENERIC_SHARE_CHILD];
    $preview = app(DataSharePackageExporter::class)->preview(GENERIC_SHARE_SCOPE, $tables);
    $bundle = app(DataShareTransferOfferManager::class)->publish(
        GENERIC_SHARE_SCOPE,
        $tables,
        $preview->previewHash,
        maxDownloads: 1,
    );
    $headers = ['Authorization' => 'Bearer '.$bundle->secret, 'Accept' => 'application/x-ndjson'];

    $first = $this->withHeaders($headers)->get(GENERIC_SHARE_OFFER_PATH.$bundle->offerId);
    $offer = DataShareTransferOffer::query()->where('offer_id', $bundle->offerId)->firstOrFail();

    expect($first->getStatusCode())->toBe(200)
        ->and($offer->status)->toBe('exhausted')
        ->and($offer->download_count)->toBe(1)
        ->and(fn () => app(DataShareTransferOfferManager::class)->bundleFor($offer))
        ->toThrow(DataSharePolicyException::class, 'cannot be copied');

    ob_start();
    $first->sendContent();
    ob_get_clean();

    $second = $this->withHeaders($headers)->get(GENERIC_SHARE_OFFER_PATH.$bundle->offerId);

    expect($second->getStatusCode())->toBe(401)
        ->and($offer->refresh()->status)->toBe('exhausted')
        ->and(DataShareEvent::query()->where('action', 'offer_exhausted')->count())->toBe(1);
});

it('refuses incorrect and expired offer secrets without exposing package bytes', function (): void {
    seedGenericDataShareFixture();
    ['bundle' => $bundle, 'offer' => $offer] = publishGenericDataShare();

    expect($this->withToken(str_repeat('x', 43))->getJson(GENERIC_SHARE_OFFER_PATH.$bundle->offerId)->getStatusCode())->toBe(401);
    $offer->forceFill(['expires_at' => now('UTC')->subSecond()])->save();
    expect($this->withToken($bundle->secret)->getJson(GENERIC_SHARE_OFFER_PATH.$bundle->offerId)->getStatusCode())->toBe(401);

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

it('explains the Data Share features and orients Data Share settings', function (): void {
    $this->actingAs(createAdminUser());

    $this->get(route('admin.system.data-share.index'))
        ->assertOk()
        ->assertDontSee('Mirror exact development tables directly, or publish an immutable offer for separately reviewed promotion.')
        ->assertSee('Publish an immutable offer for separately reviewed promotion. Pick a module, then choose the exact tables to include.')
        ->assertSee('How Data Share works')
        ->assertSee('Data Share moves selected database tables between Belimbing instances.')
        ->assertSee('Share &amp; Published', false)
        ->assertSee('Development only. Copy complete selected tables directly between Local and a configured PostgreSQL mirror.')
        ->assertSee('Paste an offer from another instance, verify and fetch it, review conflicts, then apply it.')
        ->assertSee('Capture a small set of rows to reproduce development problems. This is not a bulk transfer.')
        ->assertSee('Offer bundles contain a bearer secret. Keep them out of logs and committed files.')
        ->assertDontSee('Publish on the source.');
    $this->get(route('admin.system.data-share.settings'))
        ->assertOk()
        ->assertSee('About Data Share settings')
        ->assertSee('History')
        ->assertSee('Choose and initialize a development provider here');
});

it('stores Data Share operator configuration in Base Settings and validates source routes', function (): void {
    $this->actingAs(createAdminUser());
    Livewire::test(DataShareSettingsPage::class)
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
    $service->shouldReceive('get')->once()->with('data_share.transfer_limits.max_records')->andReturn('42');
    $settings = new DataShareSettings($service);

    expect($settings->integer('data_share.transfer_limits.max_records', 250000, 1, 10000000))->toBe(42)
        ->and($settings->integer('data_share.transfer_limits.max_records', 250000, 1, 10000000))->toBe(42);
});

it('matches destination rows by binary key on every driver', function (): void {
    Schema::create('test_data_share_binary_keys', function (Blueprint $table): void {
        $table->binary('token')->primary();
        $table->string('name');
    });

    try {
        DB::table('test_data_share_binary_keys')->insert([
            'token' => genericShareBinaryStream(GENERIC_SHARE_BINARY_PAYLOAD),
            'name' => 'keyed by bytes',
        ]);
        $definition = new DataShareTableDefinition('test_data_share_binary_keys', ['token'], []);
        $record = ['primary_key' => ['token' => ['__data_share_binary_base64' => base64_encode(GENERIC_SHARE_BINARY_PAYLOAD)]]];

        // A binary predicate bound as text returns no rows on PostgreSQL; bound as
        // a stream it finds the row on every driver.
        $existing = app(DataShareDestinationMapper::class)->findExisting($definition, $record);

        expect($existing)->not->toBeNull()
            ->and($existing['name'])->toBe('keyed by bytes')
            ->and(genericShareBinary($existing['token']))->toBe(GENERIC_SHARE_BINARY_PAYLOAD);
    } finally {
        Schema::dropIfExists('test_data_share_binary_keys');
    }
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
    $inspectedScope = null;

    try {
        $verified = app(DataSharePackageReader::class)->inspect(
            $stream,
            function ($scope, $table, array $record) use (&$rows, &$inspectedScope): void {
                $inspectedScope = $scope->name;
                $rows[$table->table][] = $record;
            },
        );
    } finally {
        fclose($stream);
    }

    expect($first->previewHash)->toBe($second->previewHash)
        ->and($verified->sha256)->toBe($export->sha256)
        ->and($inspectedScope)->toBe(GENERIC_SHARE_SCOPE)
        ->and(array_column($rows[GENERIC_SHARE_PARENT], 'primary_key'))->toBe([['id' => 2], ['id' => 10]])
        ->and($rows[GENERIC_SHARE_PARENT][0]['values']['payload'])->toBe([
            '__data_share_binary_base64' => base64_encode(GENERIC_SHARE_BINARY_PAYLOAD),
        ]);
});

it('enforces scalar, canonical-line, record, and table bounds before publishing', function (array $limits, string $message): void {
    seedGenericDataShareFixture();
    setGenericDataShareSettings($limits);

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
        ->and(genericShareBinary(DB::table(GENERIC_SHARE_PARENT)->where('id', 2)->value('payload')))->toBe(GENERIC_SHARE_BINARY_PAYLOAD)
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
    app(SettingsService::class)->set('backup.enabled', false);

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
                throw new DataShareApplyException('Injected Data Share transaction failure.');
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
    ))->toThrow(DataShareApplyException::class, 'Injected Data Share transaction failure');
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
            'Content-Type' => GENERIC_SHARE_NDJSON,
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
            'Content-Type' => GENERIC_SHARE_NDJSON,
            'Content-Length' => (string) strlen($bytes),
            'X-Data-Share-Offer-Id' => $bundle->offerId,
            'X-Data-Share-Package-Id' => $bundle->packageId,
            'X-Data-Share-Package-Sha256' => str_repeat('0', 64),
        ]),
    ]);

    expect(fn () => app(DataShareOfferFetcher::class)->fetch($bundle))
        ->toThrow(DataShareTransportException::class, 'metadata');
    expect(DataShareReceipt::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles(GENERIC_SHARE_RECEIVING_PATH))->toBe([]);
});

it('does not prune an available published offer and requires explicit outgoing cleanup', function (): void {
    seedGenericDataShareFixture();
    ['bundle' => $bundle, 'offer' => $offer, 'export' => $export] = publishGenericDataShare();
    $receipt = receiveGenericDataShare($bundle, $export);
    $receipt->forceFill(['status' => 'applied', 'received_at' => now('UTC')->subDays(30)])->save();
    app(SettingsService::class)->set('data_share.transfer_limits.incoming_retention_days', 14);
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

it('plans an insert for a row whose nullable composite foreign key is half-null, as the database itself would accept it', function (): void {
    // The house pattern for a tenant-safe optional reference: (nullable_id,
    // tenant_id) -> (id, tenant_id). tenant_id is never null, so the key is
    // half-null whenever the optional reference is absent. Under MATCH SIMPLE
    // the database does not enforce such a key; the mapper must not be
    // stricter than the constraint it models (#528). The package carries the
    // child table only, so every reference is resolved against what the
    // destination actually holds.
    $parent = 'test_data_share_half_null_parents';
    $child = 'test_data_share_half_null_children';
    $scope = 'tests/fixtures/data-share-half-null';
    $publishChildren = function () use ($scope, $child): array {
        becomeGenericDataShareSource();
        $preview = app(DataSharePackageExporter::class)->preview($scope, [$child]);
        $bundle = app(DataShareTransferOfferManager::class)->publish($scope, [$child], $preview->previewHash, actorId: 9001);
        $offer = DataShareTransferOffer::query()->where('offer_id', $bundle->offerId)->firstOrFail();

        return [$bundle, new DataShareExportResult($offer->package_id, $offer->package_path, $offer->package_sha256, $offer->bytes, [])];
    };
    $childActions = fn (DataSharePlan $plan): array => $plan->actions()->where('table_name', $child)->orderBy('sequence')->get()
        // primary_key is a JSON column (cast to array): match in PHP, since Postgres refuses json = text.
        ->mapWithKeys(fn ($action): array => [(int) $action->primary_key['id'] => $action->action])
        ->all();

    try {
        Schema::create($parent, function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unique(['id', 'tenant_id']);
        });
        Schema::create($child, function (Blueprint $table) use ($parent): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('label');
            $table->foreign(['parent_id', 'tenant_id'])->references(['id', 'tenant_id'])->on($parent);
        });
        TableRegistry::register($parent, 'Data Share Half-Null Fixture', $scope, 'test');
        TableRegistry::register($child, 'Data Share Half-Null Fixture', $scope, 'test');

        DB::table($parent)->insert([['id' => 1, 'tenant_id' => 7], ['id' => 999, 'tenant_id' => 7]]);
        DB::table($child)->insert([
            ['id' => 10, 'tenant_id' => 7, 'parent_id' => 1, 'label' => 'with parent'],
            ['id' => 11, 'tenant_id' => 7, 'parent_id' => null, 'label' => 'without parent'],
            ['id' => 12, 'tenant_id' => 7, 'parent_id' => 999, 'label' => 'parent absent at the destination'],
        ]);

        [$bundle, $export] = $publishChildren();
        DB::table($child)->delete();
        DB::table($parent)->where('id', 999)->delete();

        $plan = app(DataShareImportPlanner::class)->plan(receiveGenericDataShare($bundle, $export));

        // The fully-referenced row was always an insert; the half-null row
        // was the one classified as a conflict; a fully-set reference to an
        // absent target must stay a conflict — the relaxation is for null
        // columns only, never for missing targets.
        expect($childActions($plan))->toBe([10 => 'insert', 11 => 'insert', 12 => 'conflict'])
            ->and($plan->summary['counts'])->toBe(['insert' => 2, 'unchanged' => 0, 'conflict' => 1]);

        // Without the unresolvable row the package applies, and the half-null
        // row lands with its reference null.
        DB::table($child)->insert([
            ['id' => 10, 'tenant_id' => 7, 'parent_id' => 1, 'label' => 'with parent'],
            ['id' => 11, 'tenant_id' => 7, 'parent_id' => null, 'label' => 'without parent'],
        ]);
        [$bundle, $export] = $publishChildren();
        DB::table($child)->delete();
        $receipt = receiveGenericDataShare($bundle, $export);
        $plan = app(DataShareImportPlanner::class)->plan($receipt);
        expect($plan->summary['counts'])->toBe(['insert' => 2, 'unchanged' => 0, 'conflict' => 0]);

        app(DataSharePackageApplier::class)->apply($plan, $receipt->package_sha256, $plan->plan_hash, confirmed: true);

        expect(DB::table($child)->where('id', 11)->value('parent_id'))->toBeNull()
            ->and((int) DB::table($child)->where('id', 10)->value('parent_id'))->toBe(1);
    } finally {
        TableRegistry::unregister($child);
        TableRegistry::unregister($parent);
        genericShareDropCycle($parent, $child);
    }
});

/**
 * A table with every kind of column the redaction rules distinguish (#530):
 * a secret-looking NOT NULL column, a secret-looking nullable column, a
 * nullable foreign key, a unique column, a plain nullable column — and a
 * primary key. Own scope, own tables, dropped afterwards.
 *
 * @return array{string, string, string} [scope, parent, child]
 */
function redactionShareFixture(): array
{
    $parent = 'test_data_share_redact_parents';
    $child = 'test_data_share_redact_children';
    $scope = 'tests/fixtures/data-share-redaction';
    Schema::create($parent, function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
    });
    Schema::create($child, function (Blueprint $table) use ($parent): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('parent_id')->nullable();
        $table->string('secret_reference');
        $table->string('api_token')->nullable();
        $table->string('code')->nullable()->unique();
        $table->string('note')->nullable();
        $table->foreign('parent_id')->references('id')->on($parent);
    });
    TableRegistry::register($parent, 'Data Share Redaction Fixture', $scope, 'test');
    TableRegistry::register($child, 'Data Share Redaction Fixture', $scope, 'test');
    DB::table($parent)->insert(['id' => 1]);
    DB::table($child)->insert([
        ['id' => 10, 'parent_id' => 1, 'secret_reference' => 'ref-10', 'api_token' => 'tok-10', 'code' => 'c-10', 'note' => 'ten'],
        ['id' => 11, 'parent_id' => null, 'secret_reference' => 'ref-11', 'api_token' => null, 'code' => 'c-11', 'note' => null],
    ]);

    return [$scope, $parent, $child];
}

function redactionShareCleanup(string $parent, string $child): void
{
    TableRegistry::unregister($child);
    TableRegistry::unregister($parent);
    genericShareDropCycle($parent, $child);
}

it('offers every column for redaction, marks suggestions without ticking them, and refuses only the primary key', function (): void {
    [$scope, $parent, $child] = redactionShareFixture();

    try {
        becomeGenericDataShareSource();
        $preview = app(DataSharePackageExporter::class)->preview($scope, [$parent, $child]);
        $columns = collect($preview->advisories[$child])->keyBy('name');

        // The general listing: every column, including ones no pattern matches.
        expect($columns->keys()->all())->toBe(['id', 'parent_id', 'secret_reference', 'api_token', 'code', 'note'])
            // The matcher decorates: suggested, never redacted on its own.
            ->and($columns['secret_reference']['suggested'])->toBeTrue()
            ->and($columns['secret_reference']['redacted'])->toBeFalse()
            ->and($columns['api_token']['suggested'])->toBeTrue()
            ->and($columns['note']['suggested'])->toBeFalse()
            // Roles come from the schema block the payload already writes.
            ->and($columns['id']['roles'])->toBe(['primary_key'])
            ->and($columns['parent_id']['roles'])->toBe(['foreign_key'])
            ->and($columns['code']['roles'])->toBe(['unique'])
            ->and($columns['id']['level'])->toBe(DataShareRedactionAdvisor::LEVEL_REFUSED)
            // Nothing redacted, nothing warned, and the report says so.
            ->and($columns['secret_reference']['message'])->toBeNull()
            ->and((array) $preview->report['redactions'])->toBe([]);

        expect(fn () => app(DataSharePackageExporter::class)->preview($scope, [$parent, $child], [$child => ['id']]))
            ->toThrow(DataShareDefinitionException::class, 'primary key');
        expect(fn () => app(DataSharePackageExporter::class)->preview($scope, [$parent, $child], [$child => ['missing']]))
            ->toThrow(DataShareDefinitionException::class, 'does not exist');
        expect(fn () => app(DataSharePackageExporter::class)->preview($scope, [$parent, $child], ['other_table' => ['x']]))
            ->toThrow(DataShareDefinitionException::class, 'not in this share');
    } finally {
        redactionShareCleanup($parent, $child);
    }
});

it('names the consequence of each redaction, sized to what the destination will do', function (): void {
    [$scope, $parent, $child] = redactionShareFixture();

    try {
        becomeGenericDataShareSource();
        $preview = app(DataSharePackageExporter::class)->preview($scope, [$parent, $child], [
            $child => ['secret_reference', 'api_token', 'parent_id', 'code', 'note'],
        ]);
        $columns = collect($preview->advisories[$child])->keyBy('name');

        expect($columns['secret_reference']['level'])->toBe(DataShareRedactionAdvisor::LEVEL_UNRESTORABLE)
            ->and($columns['secret_reference']['message'])->toContain('2 rows')->toContain('unrestorable')
            ->and($columns['parent_id']['level'])->toBe(DataShareRedactionAdvisor::LEVEL_REFERENCE)
            ->and($columns['parent_id']['message'])->toContain('silently')
            ->and($columns['code']['level'])->toBe(DataShareRedactionAdvisor::LEVEL_UNIQUE)
            // A plain nullable column is not silent: it says the values will not travel.
            ->and($columns['api_token']['level'])->toBe(DataShareRedactionAdvisor::LEVEL_QUIET)
            ->and($columns['note']['level'])->toBe(DataShareRedactionAdvisor::LEVEL_QUIET)
            ->and($columns['note']['message'])->toContain('will not travel')
            // Normalised, sorted, and inside the hashed report.
            ->and((array) $preview->report['redactions'])->toBe([$child => ['api_token', 'code', 'note', 'parent_id', 'secret_reference']]);
    } finally {
        redactionShareCleanup($parent, $child);
    }
});

it('redacts at encode time, binds the map into the preview hash, and the package verifies and plans honestly', function (): void {
    [$scope, $parent, $child] = redactionShareFixture();

    try {
        becomeGenericDataShareSource();
        $exporter = app(DataSharePackageExporter::class);
        $plain = $exporter->preview($scope, [$parent, $child]);
        $redacted = $exporter->preview($scope, [$parent, $child], [$child => ['api_token', 'note']]);

        // Same rows, different map: the hash must differ, and publishing the
        // redacted map against the plain hash must be refused.
        expect($redacted->previewHash)->not->toBe($plain->previewHash);
        expect(fn () => app(DataShareTransferOfferManager::class)->publish($scope, [$parent, $child], $plain->previewHash, actorId: 9001, redactions: [$child => ['api_token', 'note']]))
            ->toThrow(DataSharePackageException::class);

        $bundle = app(DataShareTransferOfferManager::class)->publish($scope, [$parent, $child], $redacted->previewHash, actorId: 9001, redactions: [$child => ['api_token', 'note']]);
        $offer = DataShareTransferOffer::query()->where('offer_id', $bundle->offerId)->firstOrFail();
        expect($offer->metadata['redactions'])->toBe([$child => ['api_token', 'note']]);

        // Read the package itself: values null, everything else intact, and
        // the reader's own fingerprint check passes because the fingerprint
        // was taken after redaction.
        $rows = [];
        $stream = Storage::disk('local')->readStream($offer->package_path);

        try {
            $manifest = app(DataSharePackageReader::class)->manifest($stream);
            rewind($stream);
            app(DataSharePackageReader::class)->inspect($stream, function ($s, $table, array $record) use (&$rows): void {
                $rows[$table->table][(int) $record['primary_key']['id']] = $record['values'];
            });
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        expect((array) $manifest['redactions'])->toBe([$child => ['api_token', 'note']])
            ->and($rows[$child][10]['api_token'])->toBeNull()
            ->and($rows[$child][10]['note'])->toBeNull()
            ->and($rows[$child][10]['secret_reference'])->toBe('ref-10')
            ->and($rows[$child][10]['code'])->toBe('c-10');

        // Honest planning: against its own source the redacted rows differ
        // from what the destination holds, so they are conflicts, not
        // "unchanged" — a redacted package is not a faithful copy, by design.
        $export = new DataShareExportResult($offer->package_id, $offer->package_path, $offer->package_sha256, $offer->bytes, $manifest);
        $plan = app(DataShareImportPlanner::class)->plan(receiveGenericDataShare($bundle, $export));
        $childActions = $plan->actions()->where('table_name', $child)->orderBy('sequence')->get()
            ->mapWithKeys(fn ($action): array => [(int) $action->primary_key['id'] => $action->action])->all();
        expect($childActions)->toBe([10 => 'conflict', 11 => 'unchanged']);
    } finally {
        redactionShareCleanup($parent, $child);
    }
});

it('shows the sensitive-column warning outside the manifest disclosure and binds redactions on the Share tab', function (): void {
    [$scope, $parent, $child] = redactionShareFixture();

    try {
        $this->actingAs(createAdminUser());
        $component = Livewire::test(DataShareIndex::class)
            ->set('scopeName', $scope)
            ->call('previewShare')
            ->assertSet('statusVariant', 'success')
            // Nothing ticked by default; the warning names the suggested columns.
            ->assertSet('redactions', [])
            ->assertSee('This package carries columns that look sensitive')
            ->assertSee($child.'.secret_reference')
            ->assertSee($child.'.api_token')
            ->assertSee('Redact columns')
            ->assertSee('suggested: name looks like a secret');

        // The warning is not inside the disclosure: it appears before the
        // manifest heading in the rendered output.
        $html = $component->html();
        expect(strpos($html, 'This package carries columns that look sensitive'))->toBeLessThan(strpos($html, 'Table manifest'));

        // Ticking an unmatched column works; the preview clears and, once
        // reviewed again, the map is in the report and the consequence shown.
        $component->set('redactions', [$child => ['note', 'secret_reference']])
            ->assertSet('sharePreview', null)
            ->call('previewShare')
            ->assertSet('statusVariant', 'success')
            ->assertSet('sharePreview.redactions.'.$child, ['note', 'secret_reference'])
            ->assertSee('What your redactions do at the destination')
            ->assertSee('unrestorable')
            ->call('publishShare')
            ->assertSet('statusVariant', 'success');

        $offer = DataShareTransferOffer::query()->latest('id')->firstOrFail();
        expect($offer->metadata['redactions'])->toBe([$child => ['note', 'secret_reference']]);
    } finally {
        redactionShareCleanup($parent, $child);
    }
});

it('seeds suggested columns only when the one-line default says tick, and highlights otherwise', function (): void {
    [$scope, $parent, $child] = redactionShareFixture();

    try {
        $this->actingAs(createAdminUser());
        config(['data_share.redaction.suggestions' => 'highlight']);
        Livewire::test(DataShareIndex::class)
            ->set('scopeName', $scope)
            ->call('previewShare')
            ->assertSet('redactions', []);

        // The owner's ruling is highlight; the tick path exists as a
        // deliberate one-line default and must work when chosen. The seed
        // needs a first preview (to know the columns) and then applies on the
        // next review of a table the operator has not touched.
        config(['data_share.redaction.suggestions' => 'tick']);
        $component = Livewire::test(DataShareIndex::class)
            ->set('scopeName', $scope)
            ->call('previewShare')
            ->call('previewShare');
        expect($component->get('redactions')[$child] ?? [])->toBe(['api_token', 'secret_reference'])
            ->and($component->get('sharePreview.redactions.'.$child))->toBe(['api_token', 'secret_reference']);
    } finally {
        config(['data_share.redaction.suggestions' => 'highlight']);
        redactionShareCleanup($parent, $child);
    }
});
