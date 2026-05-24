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

const EBAY_METADATA_CATEGORY_TREE_ID = '100';
const EBAY_METADATA_CATEGORY_ID = '33563';
const EBAY_METADATA_CATEGORY_KEY = '100:33563';

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

    $metadata = app(EbayMetadataService::class)->categoryAspects($user->company_id, 'EBAY_MOTORS_US', EBAY_METADATA_CATEGORY_TREE_ID, EBAY_METADATA_CATEGORY_ID);

    expect($metadata)->toBeInstanceOf(MarketplaceMetadata::class)
        ->and($metadata->channel)->toBe(EbayConfiguration::CHANNEL)
        ->and($metadata->environment)->toBe('sandbox')
        ->and($metadata->marketplace_id)->toBe('EBAY_MOTORS_US')
        ->and($metadata->kind)->toBe(EbayMetadataService::KIND_CATEGORY_ASPECTS)
        ->and($metadata->key)->toBe(EBAY_METADATA_CATEGORY_KEY)
        ->and($metadata->payload['aspects'][0]['localizedAspectName'])->toBe('Brand')
        ->and($metadata->etag)->toBe('"aspect-etag"')
        ->and($metadata->expires_at)->not->toBeNull();

    Http::assertSent(function (Request $request): bool {
        return str_starts_with($request->url(), 'https://api.sandbox.ebay.com/commerce/taxonomy/v1/category_tree/100/get_item_aspects_for_category')
            && $request->hasHeader('Authorization', 'Bearer application-token-metadata')
            && $request->hasHeader('X-EBAY-C-MARKETPLACE-ID', 'EBAY_MOTORS_US')
            && $request['category_id'] === EBAY_METADATA_CATEGORY_ID;
    });
});

test('pulls and caches eBay category tree and subtree metadata', function (): void {
    $user = createAdminUser();
    configureEbayMetadataEnvironment($user->company_id);

    Http::fake([
        'https://api.sandbox.ebay.com/commerce/taxonomy/v1/category_tree/100' => Http::response([
            'categoryTreeId' => '100',
            'rootCategoryNode' => [
                'category' => ['categoryId' => '6000', 'categoryName' => 'eBay Motors'],
            ],
        ]),
        'https://api.sandbox.ebay.com/commerce/taxonomy/v1/category_tree/100/get_category_subtree*' => Http::response([
            'categorySubtreeNode' => [
                'category' => ['categoryId' => '33563', 'categoryName' => 'Calipers & Brackets'],
            ],
        ]),
    ]);

    $tree = app(EbayMetadataService::class)->categoryTree($user->company_id, 'EBAY_MOTORS_US', '100');
    $subtree = app(EbayMetadataService::class)->categorySubtree($user->company_id, 'EBAY_MOTORS_US', '100', '33563');

    expect($tree->kind)->toBe(EbayMetadataService::KIND_CATEGORY_TREE)
        ->and($tree->key)->toBe('100')
        ->and($tree->payload['rootCategoryNode']['category']['categoryName'])->toBe('eBay Motors')
        ->and($subtree->kind)->toBe(EbayMetadataService::KIND_CATEGORY_SUBTREE)
        ->and($subtree->key)->toBe('100:33563')
        ->and($subtree->payload['categorySubtreeNode']['category']['categoryName'])->toBe('Calipers & Brackets');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://api.sandbox.ebay.com/commerce/taxonomy/v1/category_tree/100'
            && $request->hasHeader('X-EBAY-C-MARKETPLACE-ID', 'EBAY_MOTORS_US');
    });

    Http::assertSent(function (Request $request): bool {
        return str_starts_with($request->url(), 'https://api.sandbox.ebay.com/commerce/taxonomy/v1/category_tree/100/get_category_subtree')
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
        'key' => EBAY_METADATA_CATEGORY_KEY,
        'payload' => ['aspects' => [['localizedAspectName' => 'Type']]],
        'fetched_at' => Carbon::now(),
        'expires_at' => Carbon::now()->addHour(),
    ]);

    Http::fake();

    $metadata = app(EbayMetadataService::class)->categoryAspects($user->company_id, 'EBAY_MOTORS_US', EBAY_METADATA_CATEGORY_TREE_ID, EBAY_METADATA_CATEGORY_ID);

    expect($metadata->payload['aspects'][0]['localizedAspectName'])->toBe('Type');
    Http::assertNothingSent();
});

test('refreshes stale category metadata and exposes refresh state', function (): void {
    $user = createAdminUser();
    configureEbayMetadataEnvironment($user->company_id);

    $stale = MarketplaceMetadata::query()->create([
        'channel' => EbayConfiguration::CHANNEL,
        'environment' => 'sandbox',
        'marketplace_id' => 'EBAY_MOTORS_US',
        'kind' => EbayMetadataService::KIND_CATEGORY_ASPECTS,
        'key' => EBAY_METADATA_CATEGORY_KEY,
        'payload' => ['aspects' => [['localizedAspectName' => 'Old Type']]],
        'fetched_at' => Carbon::now()->subDays(2),
        'expires_at' => Carbon::now()->subMinute(),
    ]);

    expect($stale->isStale())->toBeTrue()
        ->and($stale->refreshState())->toBe(MarketplaceMetadata::REFRESH_STATE_STALE);

    Http::fake([
        'https://api.sandbox.ebay.com/commerce/taxonomy/v1/category_tree/100/get_item_aspects_for_category*' => Http::response([
            'aspects' => [['localizedAspectName' => 'Fresh Type']],
        ]),
    ]);

    $metadata = app(EbayMetadataService::class)->categoryAspects($user->company_id, 'EBAY_MOTORS_US', EBAY_METADATA_CATEGORY_TREE_ID, EBAY_METADATA_CATEGORY_ID);

    expect($metadata->id)->toBe($stale->id)
        ->and($metadata->payload['aspects'][0]['localizedAspectName'])->toBe('Fresh Type')
        ->and($metadata->isFresh())->toBeTrue()
        ->and($metadata->refreshState())->toBe(MarketplaceMetadata::REFRESH_STATE_FRESH);
});

test('pulls compatibility properties and filtered property values', function (): void {
    $user = createAdminUser();
    configureEbayMetadataEnvironment($user->company_id);

    Http::fake([
        'https://api.sandbox.ebay.com/commerce/taxonomy/v1/category_tree/100/get_compatibility_properties*' => Http::response([
            'compatibilityProperties' => [
                ['name' => 'Year', 'localizedName' => 'Year'],
                ['name' => 'Make', 'localizedName' => 'Make'],
                ['name' => 'Model', 'localizedName' => 'Model'],
            ],
        ]),
        'https://api.sandbox.ebay.com/commerce/taxonomy/v1/category_tree/100/get_compatibility_property_values*' => Http::response([
            'compatibilityPropertyValues' => [
                ['value' => '135i'],
                ['value' => '135is'],
            ],
        ]),
    ]);

    $properties = app(EbayMetadataService::class)->compatibilityProperties($user->company_id, 'EBAY_MOTORS_US', EBAY_METADATA_CATEGORY_TREE_ID, EBAY_METADATA_CATEGORY_ID);
    $values = app(EbayMetadataService::class)->compatibilityPropertyValues(
        $user->company_id,
        'EBAY_MOTORS_US',
        EBAY_METADATA_CATEGORY_TREE_ID,
        EBAY_METADATA_CATEGORY_ID,
        'Model',
        ['Year' => '2011', 'Make' => 'BMW'],
    );

    expect($properties->kind)->toBe(EbayMetadataService::KIND_COMPATIBILITY_PROPERTIES)
        ->and($properties->key)->toBe(EBAY_METADATA_CATEGORY_KEY)
        ->and($properties->payload['compatibilityProperties'][0]['name'])->toBe('Year')
        ->and($values->kind)->toBe(EbayMetadataService::KIND_COMPATIBILITY_PROPERTY_VALUES)
        ->and($values->payload['compatibilityPropertyValues'][0]['value'])->toBe('135i');

    Http::assertSent(function (Request $request): bool {
        return str_starts_with($request->url(), 'https://api.sandbox.ebay.com/commerce/taxonomy/v1/category_tree/100/get_compatibility_properties')
            && $request->hasHeader('X-EBAY-C-MARKETPLACE-ID', 'EBAY_MOTORS_US')
            && $request['category_id'] === EBAY_METADATA_CATEGORY_ID;
    });

    Http::assertSent(function (Request $request): bool {
        return str_starts_with($request->url(), 'https://api.sandbox.ebay.com/commerce/taxonomy/v1/category_tree/100/get_compatibility_property_values')
            && $request['category_id'] === EBAY_METADATA_CATEGORY_ID
            && $request['compatibility_property'] === 'Model'
            && $request['filter'] === 'Year:2011,Make:BMW';
    });
});

test('pulls automotive compatibility and item condition policies by category', function (): void {
    $user = createAdminUser();
    configureEbayMetadataEnvironment($user->company_id);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/metadata/v1/marketplace/EBAY_MOTORS_US/get_automotive_parts_compatibility_policies*' => Http::response([
            'automotivePartsCompatibilityPolicies' => [
                [
                    'categoryTreeId' => '100',
                    'categoryId' => EBAY_METADATA_CATEGORY_ID,
                    'compatibilityBasedOn' => 'ASSEMBLY',
                    'maxNumberOfCompatibleVehicles' => 300,
                ],
            ],
        ]),
        'https://api.sandbox.ebay.com/sell/metadata/v1/marketplace/EBAY_MOTORS_US/get_item_condition_policies*' => Http::response([
            'itemConditionPolicies' => [
                [
                    'categoryId' => EBAY_METADATA_CATEGORY_ID,
                    'itemConditions' => [
                        ['conditionId' => '3000', 'conditionDescription' => 'Used'],
                    ],
                ],
            ],
        ]),
    ]);

    $compatibility = app(EbayMetadataService::class)->automotivePartsCompatibilityPolicies($user->company_id, 'EBAY_MOTORS_US', [EBAY_METADATA_CATEGORY_ID]);
    $conditions = app(EbayMetadataService::class)->itemConditionPolicies($user->company_id, 'EBAY_MOTORS_US', [EBAY_METADATA_CATEGORY_ID]);

    expect($compatibility->kind)->toBe(EbayMetadataService::KIND_AUTOMOTIVE_PARTS_COMPATIBILITY_POLICIES)
        ->and($compatibility->key)->toBe(EBAY_METADATA_CATEGORY_ID)
        ->and($compatibility->payload['automotivePartsCompatibilityPolicies'][0]['compatibilityBasedOn'])->toBe('ASSEMBLY')
        ->and($conditions->kind)->toBe(EbayMetadataService::KIND_ITEM_CONDITION_POLICIES)
        ->and($conditions->payload['itemConditionPolicies'][0]['itemConditions'][0]['conditionId'])->toBe('3000');

    Http::assertSent(function (Request $request): bool {
        return str_starts_with($request->url(), 'https://api.sandbox.ebay.com/sell/metadata/v1/marketplace/EBAY_MOTORS_US/get_automotive_parts_compatibility_policies')
            && $request->hasHeader('Authorization', 'Bearer application-token-metadata')
            && $request->hasHeader('Accept-Encoding', 'gzip')
            && $request['filter'] === 'categoryIds:{33563}';
    });

    Http::assertSent(function (Request $request): bool {
        return str_starts_with($request->url(), 'https://api.sandbox.ebay.com/sell/metadata/v1/marketplace/EBAY_MOTORS_US/get_item_condition_policies')
            && $request['filter'] === 'categoryIds:{33563}';
    });
});

test('caches no-content metadata responses as an empty payload', function (): void {
    $user = createAdminUser();
    configureEbayMetadataEnvironment($user->company_id);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/metadata/v1/marketplace/EBAY_MOTORS_US/get_automotive_parts_compatibility_policies*' => Http::response('', 204),
    ]);

    $metadata = app(EbayMetadataService::class)->automotivePartsCompatibilityPolicies($user->company_id, 'EBAY_MOTORS_US', ['1']);

    expect($metadata->payload)->toBe([])
        ->and($metadata->isFresh())->toBeTrue();
});

test('surfaces eBay metadata failures with the integration exchange id', function (): void {
    $user = createAdminUser();
    configureEbayMetadataEnvironment($user->company_id);

    Http::fake([
        'https://api.sandbox.ebay.com/commerce/taxonomy/v1/category_tree/100/get_item_aspects_for_category*' => Http::response(['errors' => [['message' => 'nope']]], 500),
    ]);

    expect(fn () => app(EbayMetadataService::class)->categoryAspects($user->company_id, 'EBAY_MOTORS_US', EBAY_METADATA_CATEGORY_TREE_ID, EBAY_METADATA_CATEGORY_ID))
        ->toThrow(function (MarketplaceOperationException $exception): void {
            expect($exception->context['status'])->toBe(500)
                ->and($exception->context['exchange_id'] ?? null)->toStartWith('ix_');
        });
});
