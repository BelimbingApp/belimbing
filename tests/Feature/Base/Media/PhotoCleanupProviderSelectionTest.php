<?php

use App\Base\Media\PhotoCleanup\Contracts\ImageProviderCredentialStore;
use App\Base\Media\PhotoCleanup\Contracts\PhotoCleanupProvider;
use App\Base\Media\PhotoCleanup\PhotoCleanupConnectionTester;
use App\Base\Media\PhotoCleanup\PhotoCleanupException;
use App\Base\Media\PhotoCleanup\PhotoCleanupProviderRegistry;
use App\Base\Media\PhotoCleanup\PhotoCleanupSelection;
use App\Base\Media\PhotoCleanup\PhotoRoomClient;
use App\Base\Media\PhotoCleanup\PoofClient;
use App\Base\Media\PhotoCleanup\PoofConfiguration;
use App\Base\Media\PhotoCleanup\ResolvingPhotoCleanupProvider;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Support\Facades\Http;

const POOF_REMOVE_ENDPOINT = 'https://api.poof.bg/v1/remove';

function configurePoof(string $apiKey = 'poof-key', ?int $companyId = null): int
{
    $companyId ??= Company::factory()->create()->id;

    app(ImageProviderCredentialStore::class)->upsert(
        $companyId,
        PoofConfiguration::PROVIDER,
        [
            'display_name' => PoofConfiguration::PROVIDER_LABEL,
            'base_url' => PoofConfiguration::API_BASE_URL,
            'credentials' => ['api_key' => $apiKey],
            'connection_config' => [],
        ],
    );

    return $companyId;
}

it('registers photoroom and poof adapters, not credential-only providers', function (): void {
    $registry = app(PhotoCleanupProviderRegistry::class);

    expect($registry->supports('photoroom'))->toBeTrue()
        ->and($registry->supports('poof'))->toBeTrue()
        ->and($registry->supports('claid'))->toBeFalse()
        ->and($registry->supports('stability'))->toBeFalse()
        ->and($registry->adapter('photoroom'))->toBeInstanceOf(PhotoRoomClient::class)
        ->and($registry->adapter('poof'))->toBeInstanceOf(PoofClient::class)
        ->and($registry->adapter('claid'))->toBeNull();
});

it('does not offer a handshake for poof (no cheap read or probe endpoint)', function (): void {
    $tester = app(PhotoCleanupConnectionTester::class);

    expect($tester->supports('poof'))->toBeFalse()
        ->and($tester->supports('photoroom'))->toBeTrue();
});

it('defaults the active photo-cleanup provider to photoroom when no choice is stored', function (): void {
    $companyId = Company::factory()->create()->id;

    $selection = app(PhotoCleanupSelection::class);

    expect($selection->activeProviderKey($companyId))->toBe('photoroom')
        ->and($selection->resolveProvider($companyId))->toBeInstanceOf(PhotoRoomClient::class);
});

it('persists and reflects the operator choice through the selection', function (): void {
    $companyId = configurePoof();

    $selection = app(PhotoCleanupSelection::class);

    expect($selection->activeProviderKey($companyId))->toBe('photoroom');

    $selection->setActiveProvider($companyId, 'poof');

    expect($selection->activeProviderKey($companyId))->toBe('poof')
        ->and($selection->resolveProvider($companyId))->toBeInstanceOf(PoofClient::class);
});

it('fails with a clear operator-facing error when the chosen provider has no adapter', function (): void {
    $companyId = Company::factory()->create()->id;

    app(SettingsService::class)->set(PhotoCleanupSelection::SETTING_KEY, 'claid', Scope::company($companyId));

    expect(fn () => app(PhotoCleanupSelection::class)->resolveProvider($companyId))
        ->toThrow(fn (PhotoCleanupException $e) => expect($e->getMessage())
            ->toContain('claid')
            ->toContain('not available'));
});

it('delegates cleanup through the bound proxy to the active adapter per company', function (): void {
    $companyId = configurePoof();
    app(PhotoCleanupSelection::class)->setActiveProvider($companyId, 'poof');

    Http::fake([
        POOF_REMOVE_ENDPOINT => Http::response('POOF-PNG-BYTES', 200, ['Content-Type' => 'image/png']),
    ]);

    $result = app(PhotoCleanupProvider::class)->removeBackground('image-bytes', 'photo.jpg', 'image/jpeg', $companyId);

    expect($result)
        ->toBeArray()
        ->and($result['bytes'])->toBe('POOF-PNG-BYTES')
        ->and($result['provider'])->toBe('poof')
        ->and($result['provider_label'])->toBe('Poof');

    Http::assertSent(fn ($request) => $request->url() === POOF_REMOVE_ENDPOINT
        && $request->hasHeader('x-api-key', 'poof-key')
        && $request->method() === 'POST'
        && str_contains($request->body(), 'image_file')
        && str_contains($request->body(), "name=\"size\"\r\n\r\nfull\r\n"));
});

it('keeps the engine sealed: the bound contract is the resolving proxy, never a concrete client', function (): void {
    expect(app(PhotoCleanupProvider::class))->toBeInstanceOf(ResolvingPhotoCleanupProvider::class)
        ->and(app(PhotoCleanupProvider::class))->not->toBeInstanceOf(PhotoRoomClient::class)
        ->and(app(PhotoCleanupProvider::class))->not->toBeInstanceOf(PoofClient::class);
});

it('runs poof background removal and returns cleaned bytes with poof provenance', function (): void {
    $companyId = configurePoof();

    Http::fake([
        POOF_REMOVE_ENDPOINT => Http::response('CLEAN-PNG', 200, ['Content-Type' => 'image/png']),
    ]);

    $result = app(PoofClient::class)->removeBackground('img', 'p.jpg', 'image/jpeg', $companyId);

    expect($result['bytes'])->toBe('CLEAN-PNG')
        ->and($result['provider'])->toBe('poof')
        ->and($result['provider_label'])->toBe('Poof');
});

it('reports not configured for poof when no key is stored', function (): void {
    $companyId = Company::factory()->create()->id;

    expect(fn () => app(PoofClient::class)->removeBackground('img', 'p.jpg', 'image/jpeg', $companyId))
        ->toThrow(function (PhotoCleanupException $e): void {
            expect($e->getMessage())->toContain('Poof')->toContain('not configured');
        });
});

it('reports request failed with the poof label on a provider error', function (): void {
    $companyId = configurePoof();

    Http::fake([
        POOF_REMOVE_ENDPOINT => Http::response('boom', 500),
    ]);

    expect(fn () => app(PoofClient::class)->removeBackground('img', 'p.jpg', 'image/jpeg', $companyId))
        ->toThrow(function (PhotoCleanupException $e): void {
            expect($e->getMessage())->toContain('Poof')->toContain('500');
        });
});
