<?php

use App\Base\Integration\Services\OAuthTokenStore;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Ebay\EbayMetadataService;
use App\Modules\Commerce\Marketplace\Exceptions\MarketplaceOperationException;
use App\Modules\Commerce\Marketplace\Models\MarketplaceMetadata;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

function configureEbayMetadataEnvironment(int $companyId): void
{
    $scope = Scope::company($companyId);
    $settings = app(SettingsService::class);
    $settings->set('marketplace.ebay.environment', 'sandbox', $scope);
    $settings->set('marketplace.ebay.marketplace_id', 'EBAY_US', $scope);
    $settings->set('marketplace.ebay.client_id', 'client-metadata-test', $scope);
    $settings->set('marketplace.ebay.client_secret', 'secret-metadata-test', $scope, encrypted: true);

    app(OAuthTokenStore::class)->persist(
        EbayConfiguration::CHANNEL,
        $scope,
        [
            'access_token' => 'application-token-metadata',
            'expires_in' => 3600,
        ],
        EbayConfiguration::APPLICATION_SCOPES,
        EbayConfiguration::APPLICATION_TOKEN_ACCOUNT_KEY,
        metadata: ['token_kind' => 'application'],
    );
}

test('pulls and caches eBay Motors category aspects with application auth', function (): void {
    $user = createAdminUser();
    configureEbayMetadataEnvironment($user->company_id);

    Http::fake([
        'https://api.sandbox.ebay.com/commerce/taxonomy/v1/category_tree/100/get_item_aspects_for_category*' => Http::response([
            'aspects' => [
                [
                    'localizedAspectName' => 'Brand',
                    'aspectConstraint' => ['aspectRequired' => true],
                ],
                [
                    'localizedAspectName' => 'Manufacturer Part Number',
                    'aspectConstraint' => ['aspectRequired' => true],
                ],
            ],
        ], 200, ['ETag' => '"aspect-etag"']),
    ]);

    $metadata = app(EbayMetadataService::class)->categoryAspects($user->company_id, 'EBAY_MOTORS_US', '100', '33563');

    expect($metadata)->toBeInstanceOf(MarketplaceMetadata::class)
        ->and($metadata->channel)->toBe(EbayConfiguration::CHANNEL)
        ->and($metadata->environment)->toBe('sandbox')
        ->and($metadata->marketplace_id)->toBe('EBAY_MOTORS_US')
        ->and($metadata->kind)->toBe(EbayMetadataService::KIND_CATEGORY_ASPECTS)
        ->and($metadata->key)->toBe('100:33563')
        ->and($metadata->payload['aspects'][0]['localizedAspectName'])->toBe('Brand')
        ->and($metadata->etag)->toBe('"aspect-etag"')
        ->and($metadata->expires_at)->not->toBeNull();

    Http::assertSent(function (Request $request): bool {
        return str_starts_with($request->url(), 'https://api.sandbox.ebay.com/commerce/taxonomy/v1/category_tree/100/get_item_aspects_for_category')
            && $request->hasHeader('Authorization', 'Bearer application-token-metadata')
            && $request->hasHeader('X-EBAY-C-MARKETPLACE-ID', 'EBAY_MOTORS_US')
            && $request['category_id'] === '33563';
    });
});

test('reuses fresh category aspect metadata without calling eBay', function (): void {
    $user = createAdminUser();
    configureEbayMetadataEnvironment($user->company_id);

    MarketplaceMetadata::query()->create([
        'channel' => EbayConfiguration::CHANNEL,
        'environment' => 'sandbox',
        'marketplace_id' => 'EBAY_MOTORS_US',
        'kind' => EbayMetadataService::KIND_CATEGORY_ASPECTS,
        'key' => '100:33563',
        'payload' => ['aspects' => [['localizedAspectName' => 'Type']]],
        'fetched_at' => Carbon::now(),
        'expires_at' => Carbon::now()->addHour(),
    ]);

    Http::fake();

    $metadata = app(EbayMetadataService::class)->categoryAspects($user->company_id, 'EBAY_MOTORS_US', '100', '33563');

    expect($metadata->payload['aspects'][0]['localizedAspectName'])->toBe('Type');
    Http::assertNothingSent();
});

test('surfaces eBay metadata failures with the integration exchange id', function (): void {
    $user = createAdminUser();
    configureEbayMetadataEnvironment($user->company_id);

    Http::fake([
        'https://api.sandbox.ebay.com/commerce/taxonomy/v1/category_tree/100/get_item_aspects_for_category*' => Http::response(['errors' => [['message' => 'nope']]], 500),
    ]);

    expect(fn () => app(EbayMetadataService::class)->categoryAspects($user->company_id, 'EBAY_MOTORS_US', '100', '33563'))
        ->toThrow(function (MarketplaceOperationException $exception): void {
            expect($exception->context['status'])->toBe(500)
                ->and($exception->context['exchange_id'] ?? null)->toStartWith('ix_');
        });
});
