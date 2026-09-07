<?php

require_once __DIR__.'/Support/generic_data_share_helpers.php';

use App\Base\Database\Exceptions\DataSharePackageException;
use App\Base\Database\Exceptions\DataShareTransportException;
use App\Base\Database\Livewire\DataShare\Index as DataShareIndex;
use App\Base\Database\Models\DataShareEvent;
use App\Base\Database\Models\DataShareReceipt;
use App\Base\Database\Models\TableRegistry;
use App\Base\Database\Services\DataShare\DataShareImportPlanner;
use App\Base\Database\Services\DataShare\DataShareOfferFetcher;
use App\Base\Database\Services\DataShare\DataSharePackageExporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('local');
    config(['app.env' => 'testing']);
    seedGenericDataShareFixtureSettings();
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

function seedGenericDataShareFixtureSettings(): void
{
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
}

it('records fetch_failed without secrets when response headers mismatch', function (): void {
    seedGenericDataShareFixture();
    ['bundle' => $bundle, 'export' => $export] = publishGenericDataShare();
    $bytes = Storage::disk('local')->get($export->path);
    becomeGenericDataShareDestination();
    Http::fake([
        $bundle->endpoint => Http::response($bytes, 200, [
            'Content-Type' => GENERIC_SHARE_NDJSON,
            'Content-Length' => (string) strlen($bytes),
            'X-Data-Share-Offer-Id' => '01MISMATCHOFFERID000000000',
            'X-Data-Share-Package-Id' => $bundle->packageId,
            'X-Data-Share-Package-Sha256' => $bundle->packageSha256,
        ]),
    ]);

    expect(fn () => app(DataShareOfferFetcher::class)->fetch($bundle))
        ->toThrow(DataShareTransportException::class, 'metadata');

    $row = DataShareEvent::query()->where('action', 'fetch_failed')->sole();
    expect($row->error_summary)->toContain('metadata')
        ->and($row->metadata)->not->toHaveKey('secret')
        ->and(json_encode($row->metadata))->not->toContain($bytes)
        ->and($row->metadata['offer_id'] ?? null)->toBe($bundle->offerId);
});

it('records plan_failed when the Incoming receipt hash no longer matches', function (): void {
    seedGenericDataShareFixture();
    ['bundle' => $bundle, 'export' => $export] = publishGenericDataShare();
    $receipt = receiveGenericDataShare($bundle, $export);
    $receipt->forceFill(['package_sha256' => str_repeat('a', 64)])->save();

    expect(fn () => app(DataShareImportPlanner::class)->plan($receipt->fresh()))
        ->toThrow(DataSharePackageException::class);

    $row = DataShareEvent::query()->where('action', 'plan_failed')->sole();
    expect($row->error_summary)->not->toBeEmpty()
        ->and($row->package_id)->toBe($receipt->package_id);
});

it('records export_failed on a stale preview hash and exported on success', function (): void {
    seedGenericDataShareFixture();
    becomeGenericDataShareSource();
    $exporter = app(DataSharePackageExporter::class);
    $preview = $exporter->preview(GENERIC_SHARE_SCOPE, [GENERIC_SHARE_PARENT, GENERIC_SHARE_CHILD]);

    expect(fn () => $exporter->export(
        GENERIC_SHARE_SCOPE,
        [GENERIC_SHARE_PARENT, GENERIC_SHARE_CHILD],
        '01EXPORTFAILTESTOFFER00000',
        now('UTC')->addHour()->toIso8601String(),
        str_repeat('0', 64),
    ))->toThrow(DataSharePackageException::class);

    expect(DataShareEvent::query()->where('action', 'export_failed')->count())->toBe(1);

    $result = $exporter->export(
        GENERIC_SHARE_SCOPE,
        [GENERIC_SHARE_PARENT, GENERIC_SHARE_CHILD],
        '01EXPORTOKTESTOFFER0000000',
        now('UTC')->addHour()->toIso8601String(),
        $preview->previewHash,
    );

    $exported = DataShareEvent::query()->where('action', 'exported')->sole();
    expect($exported->package_id)->toBe($result->packageId)
        ->and($exported->metadata['bytes'] ?? null)->toBe($result->bytes);
});

it('lists every action class on the History tab and mirrors failures on the CLI', function (): void {
    $user = createAdminUser();
    $actions = [
        'offer_published', 'offer_revoked', 'offer_expired', 'offer_exhausted', 'offer_downloaded',
        'offer_fetched', 'received', 'planned', 'applied', 'apply_failed', 'package_pruned',
        'exported', 'export_failed', 'fetch_failed', 'plan_failed',
    ];

    foreach ($actions as $i => $action) {
        DataShareEvent::query()->create([
            'package_id' => 'pkg-'.$i,
            'action' => $action,
            'actor_id' => $user->id,
            'scope_name' => GENERIC_SHARE_SCOPE,
            'metadata' => ['offer_id' => 'offer-'.$i],
            'error_summary' => str_ends_with($action, '_failed') ? $action.' reason' : null,
            'created_at' => now('UTC')->subSeconds(count($actions) - $i),
        ]);
    }

    $component = Livewire::actingAs($user)->test(DataShareIndex::class)
        ->assertSee('History')
        ->assertSee('offer_published')
        ->assertSee('fetch_failed')
        ->assertSee('exported');

    foreach ($actions as $action) {
        $component->assertSee($action);
    }

    $component->set('historyActionClass', 'failures')
        ->assertSee('fetch_failed')
        ->assertSee('plan_failed')
        ->assertSee('export_failed')
        ->assertSee('apply_failed')
        ->assertDontSee('offer_published');

    $failedIds = DataShareEvent::query()->where('action', 'like', '%_failed')->orderByDesc('id')->pluck('id')->all();

    Artisan::call('blb:db:share:history', ['--failures' => true, '--json' => true]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    expect(array_column($payload, 'id'))->toBe($failedIds);
});

it('refuses History for a non-operator tenant on the page and CLI', function (): void {
    $user = createNonOperatorDatabaseConsoleAdmin();

    Livewire::actingAs($user)->test(DataShareIndex::class)
        ->assertForbidden();

    expect(Artisan::call('blb:db:share:history', ['--json' => true]))->toBe(1)
        ->and(DataShareEvent::query()->count())->toBe(0);
});

it('never renders shared table payload values in History HTML', function (): void {
    seedGenericDataShareFixture();
    $sentinel = 'Éclair شركة';
    $user = createAdminUser();
    DataShareEvent::query()->create([
        'package_id' => 'pkg-sentinel',
        'action' => 'exported',
        'actor_id' => $user->id,
        'scope_name' => GENERIC_SHARE_SCOPE,
        'metadata' => ['offer_id' => 'offer-sentinel', 'bytes' => 12],
        'created_at' => now('UTC'),
    ]);

    Livewire::actingAs($user)->test(DataShareIndex::class)
        ->assertSee('History')
        ->assertSee('exported')
        ->assertDontSee($sentinel);
});

it('does not leave a fetch_failed row when recordFailure is not reached on success path', function (): void {
    // Mutant guard from the issue: a successful fetch records offer_fetched, not fetch_failed.
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

    app(DataShareOfferFetcher::class)->fetch($bundle);

    expect(DataShareEvent::query()->where('action', 'fetch_failed')->count())->toBe(0)
        ->and(DataShareEvent::query()->where('action', 'offer_fetched')->count())->toBe(1)
        ->and(DataShareReceipt::query()->count())->toBe(1);
});
