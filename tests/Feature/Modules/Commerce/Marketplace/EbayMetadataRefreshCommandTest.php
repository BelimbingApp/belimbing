<?php

use App\Base\Integration\Services\OAuthTokenStore;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Ebay\EbayMetadataService;
use App\Modules\Commerce\Marketplace\Models\MarketplaceMetadata;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const EBAY_METADATA_REFRESH_CATEGORY_ID = '33563';
const EBAY_METADATA_REFRESH_CATEGORY_KEY = '100:33563';
const EBAY_METADATA_REFRESH_CATEGORY_FILTER = 'categoryIds:{33563}';
function configureEbayMetadataRefreshCommandEnvironment(int $companyId): void
{
    $scope = Scope::company($companyId);
    $settings = app(SettingsService::class);
    $settings->set('marketplace.ebay.environment', 'sandbox', $scope);
    $settings->set('marketplace.ebay.marketplace_id', 'EBAY_US', $scope);
    $settings->set('marketplace.ebay.client_id', 'client-metadata-command-test', $scope);
    $settings->set('marketplace.ebay.client_secret', 'secret-metadata-command-test', $scope, encrypted: true);

    app(OAuthTokenStore::class)->persist(
        EbayConfiguration::CHANNEL,
        $scope,
        [
            'access_token' => 'application-token-metadata-command',
            'expires_in' => 3600,
        ],
        EbayConfiguration::APPLICATION_SCOPES,
        EbayConfiguration::APPLICATION_TOKEN_ACCOUNT_KEY,
        metadata: ['token_kind' => 'application'],
    );
}

test('eBay metadata refresh command requires a category tree id', function (): void {
    $this->artisan('commerce:marketplace:ebay:metadata-refresh')
        ->assertFailed()
        ->expectsOutputToContain('Pass --category-tree-id');
});

test('eBay metadata refresh command caches tree and category metadata for a company', function (): void {
    $user = createAdminUser();
    configureEbayMetadataRefreshCommandEnvironment($user->company_id);

    Http::fake([
        'https://api.sandbox.ebay.com/commerce/taxonomy/v1/category_tree/100' => Http::response([
            'categoryTreeId' => '100',
            'rootCategoryNode' => ['category' => ['categoryId' => '6000', 'categoryName' => 'eBay Motors']],
        ]),
        'https://api.sandbox.ebay.com/commerce/taxonomy/v1/category_tree/100/get_category_subtree*' => Http::response([
            'categorySubtreeNode' => ['category' => ['categoryId' => EBAY_METADATA_REFRESH_CATEGORY_ID, 'categoryName' => 'Calipers & Brackets']],
        ]),
        'https://api.sandbox.ebay.com/commerce/taxonomy/v1/category_tree/100/get_item_aspects_for_category*' => Http::response([
            'aspects' => [
                ['localizedAspectName' => 'Brand'],
                ['localizedAspectName' => 'Manufacturer Part Number'],
            ],
        ]),
        'https://api.sandbox.ebay.com/commerce/taxonomy/v1/category_tree/100/get_compatibility_properties*' => Http::response([
            'compatibilityProperties' => [
                ['name' => 'Year'],
                ['name' => 'Make'],
            ],
        ]),
        'https://api.sandbox.ebay.com/sell/metadata/v1/marketplace/EBAY_MOTORS_US/get_automotive_parts_compatibility_policies*' => Http::response([
            'automotivePartsCompatibilityPolicies' => [
                ['categoryId' => EBAY_METADATA_REFRESH_CATEGORY_ID, 'compatibilityBasedOn' => 'ASSEMBLY'],
            ],
        ]),
        'https://api.sandbox.ebay.com/sell/metadata/v1/marketplace/EBAY_MOTORS_US/get_item_condition_policies*' => Http::response([
            'itemConditionPolicies' => [
                ['categoryId' => EBAY_METADATA_REFRESH_CATEGORY_ID, 'itemConditions' => [['conditionId' => '3000']]],
            ],
        ]),
    ]);

    $this->artisan('commerce:marketplace:ebay:metadata-refresh', [
        '--company-id' => $user->company_id,
        '--marketplace-id' => 'EBAY_MOTORS_US',
        '--category-tree-id' => '100',
        '--category-id' => [EBAY_METADATA_REFRESH_CATEGORY_ID],
    ])->assertSuccessful();

    expect(MarketplaceMetadata::query()->where('kind', EbayMetadataService::KIND_CATEGORY_TREE)->where('key', '100')->exists())->toBeTrue()
        ->and(MarketplaceMetadata::query()->where('kind', EbayMetadataService::KIND_CATEGORY_SUBTREE)->where('key', EBAY_METADATA_REFRESH_CATEGORY_KEY)->exists())->toBeTrue()
        ->and(MarketplaceMetadata::query()->where('kind', EbayMetadataService::KIND_CATEGORY_ASPECTS)->where('key', EBAY_METADATA_REFRESH_CATEGORY_KEY)->exists())->toBeTrue()
        ->and(MarketplaceMetadata::query()->where('kind', EbayMetadataService::KIND_COMPATIBILITY_PROPERTIES)->where('key', EBAY_METADATA_REFRESH_CATEGORY_KEY)->exists())->toBeTrue()
        ->and(MarketplaceMetadata::query()->where('kind', EbayMetadataService::KIND_AUTOMOTIVE_PARTS_COMPATIBILITY_POLICIES)->where('key', EBAY_METADATA_REFRESH_CATEGORY_ID)->exists())->toBeTrue()
        ->and(MarketplaceMetadata::query()->where('kind', EbayMetadataService::KIND_ITEM_CONDITION_POLICIES)->where('key', EBAY_METADATA_REFRESH_CATEGORY_ID)->exists())->toBeTrue();

    Http::assertSent(function (Request $request): bool {
        return str_starts_with($request->url(), 'https://api.sandbox.ebay.com/sell/metadata/v1/marketplace/EBAY_MOTORS_US/get_item_condition_policies')
            && $request['filter'] === EBAY_METADATA_REFRESH_CATEGORY_FILTER;
    });
});
