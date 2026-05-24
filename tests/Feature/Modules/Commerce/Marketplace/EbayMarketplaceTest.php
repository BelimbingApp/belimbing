<?php

use App\Base\Integration\Services\OAuthTokenStore;
use App\Base\Media\Models\MediaAsset;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Catalog\Models\Attribute as CatalogAttribute;
use App\Modules\Commerce\Catalog\Models\AttributeValue;
use App\Modules\Commerce\Catalog\Models\Description as CatalogDescription;
use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Inventory\Models\ItemFitment;
use App\Modules\Commerce\Inventory\Models\ItemPhoto;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Ebay\EbayMarketplaceChannel;
use App\Modules\Commerce\Marketplace\Ebay\EbayMetadataService;
use App\Modules\Commerce\Marketplace\Livewire\Ebay\Index as MarketplaceIndex;
use App\Modules\Commerce\Marketplace\Livewire\Ebay\Settings as EbaySettings;
use App\Modules\Commerce\Marketplace\Models\AccountResource;
use App\Modules\Commerce\Marketplace\Models\AspectMapping;
use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Marketplace\Models\ListingDraft;
use App\Modules\Commerce\Marketplace\Models\MarketplaceMetadata;
use App\Modules\Commerce\Marketplace\Models\ProductReference;
use App\Modules\Commerce\Marketplace\Services\MarketplaceChannelRegistry;
use App\Modules\Commerce\Plugins\Services\CommercePluginRegistry;
use App\Modules\Commerce\Sales\Models\Order;
use App\Modules\Commerce\Sales\Models\OrderLine;
use App\Modules\Commerce\Sales\Models\Sale;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

const EBAY_FIXTURE_TITLE = '2008 Honda Civic driver side headlight';
const EBAY_FIXTURE_LISTING_ID = '1234567890';
const EBAY_FIXTURE_PRICE_DECIMAL = '120.00';
const EBAY_FIXTURE_SKU = 'HAM-HEADLIGHT-0001';

function configureEbayMarketplaceForCompany(int $companyId, array $scopes): void
{
    $scope = Scope::company($companyId);
    $settings = app(SettingsService::class);
    $settings->set('marketplace.ebay.environment', 'sandbox', $scope);
    $settings->set('marketplace.ebay.marketplace_id', 'EBAY_US', $scope);
    $settings->set('marketplace.ebay.client_id', 'client-123', $scope);
    $settings->set('marketplace.ebay.client_secret', 'secret-456', $scope, encrypted: true);
    $settings->set('marketplace.ebay.redirect_uri', 'https://blb.test/commerce/marketplace/ebay/oauth/callback', $scope);

    app(OAuthTokenStore::class)->persist(
        EbayConfiguration::CHANNEL,
        $scope,
        [
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_in' => 3600,
        ],
        $scopes,
    );
}

function seedReadyEbayListingInputs(Item $item, int $companyId): void
{
    $scope = Scope::company($companyId);
    $settings = app(SettingsService::class);
    $settings->set('marketplace.ebay.default_return_policy_id', 'RET-1', $scope);
    $settings->set('marketplace.ebay.default_fulfillment_policy_id', 'FUL-1', $scope);
    $settings->set('marketplace.ebay.default_payment_policy_id', 'PAY-1', $scope);
    $settings->set('marketplace.ebay.default_merchant_location_key', 'california_shop', $scope);

    $template = ProductTemplate::factory()->create([
        'company_id' => $companyId,
        'metadata' => [
            'marketplace' => [
                'ebay' => [
                    'marketplace_id' => 'EBAY_MOTORS_US',
                    'category_tree_id' => '100',
                    'category_id' => '33563',
                ],
            ],
        ],
    ]);

    $item->update([
        'product_template_id' => $template->id,
        'title' => 'BMW rear brake caliper pair',
        'quantity_on_hand' => 1,
        'target_price_amount' => 25000,
        'currency_code' => 'USD',
        'status' => Item::STATUS_READY,
    ]);

    $brand = CatalogAttribute::factory()->create([
        'company_id' => $companyId,
        'code' => 'brand',
        'name' => 'Brand',
    ]);
    $condition = CatalogAttribute::factory()->create([
        'company_id' => $companyId,
        'code' => 'condition_grade',
        'name' => 'Condition Grade',
    ]);

    AttributeValue::factory()->create([
        'item_id' => $item->id,
        'attribute_id' => $brand->id,
        'display_value' => 'BMW',
        'value' => ['text' => 'BMW'],
    ]);
    AttributeValue::factory()->create([
        'item_id' => $item->id,
        'attribute_id' => $condition->id,
        'display_value' => 'Used',
        'value' => ['text' => 'Used'],
    ]);

    AspectMapping::query()->create([
        'company_id' => $companyId,
        'catalog_attribute_id' => $brand->id,
        'channel' => EbayConfiguration::CHANNEL,
        'marketplace_id' => 'EBAY_MOTORS_US',
        'category_tree_id' => '100',
        'category_id' => '33563',
        'internal_attribute_code' => 'brand',
        'ebay_aspect_name' => 'Brand',
        'value_normalization' => AspectMapping::NORMALIZATION_COPY,
        'requirement_status' => AspectMapping::REQUIREMENT_REQUIRED,
        'mapping_confidence' => AspectMapping::CONFIDENCE_MANUAL,
        'is_enabled' => true,
    ]);
    AspectMapping::query()->create([
        'company_id' => $companyId,
        'catalog_attribute_id' => $condition->id,
        'channel' => EbayConfiguration::CHANNEL,
        'marketplace_id' => 'EBAY_MOTORS_US',
        'category_tree_id' => '100',
        'category_id' => '33563',
        'internal_attribute_code' => 'condition_grade',
        'ebay_aspect_name' => 'Condition',
        'value_normalization' => AspectMapping::NORMALIZATION_COPY,
        'requirement_status' => AspectMapping::REQUIREMENT_OPTIONAL,
        'mapping_confidence' => AspectMapping::CONFIDENCE_MANUAL,
        'is_enabled' => true,
    ]);

    MarketplaceMetadata::query()->create([
        'channel' => EbayConfiguration::CHANNEL,
        'environment' => 'sandbox',
        'marketplace_id' => 'EBAY_MOTORS_US',
        'kind' => EbayMetadataService::KIND_CATEGORY_ASPECTS,
        'key' => '100:33563',
        'payload' => ['aspects' => [[
            'localizedAspectName' => 'Brand',
            'aspectConstraint' => ['aspectRequired' => true],
        ]]],
        'fetched_at' => Carbon::now(),
        'expires_at' => Carbon::now()->addDay(),
    ]);

    foreach ([
        [AccountResource::KIND_RETURN_POLICY, 'RET-1', 'Returns'],
        [AccountResource::KIND_FULFILLMENT_POLICY, 'FUL-1', 'Shipping'],
        [AccountResource::KIND_PAYMENT_POLICY, 'PAY-1', 'Payments'],
        [AccountResource::KIND_INVENTORY_LOCATION, 'california_shop', 'California shop'],
    ] as [$kind, $externalId, $name]) {
        AccountResource::query()->create([
            'company_id' => $companyId,
            'channel' => EbayConfiguration::CHANNEL,
            'marketplace_id' => 'EBAY_US',
            'kind' => $kind,
            'external_id' => $externalId,
            'name' => $name,
            'status' => 'ENABLED',
            'payload' => [],
            'imported_at' => Carbon::now(),
        ]);
    }

    ItemFitment::query()->create([
        'company_id' => $companyId,
        'item_id' => $item->id,
        'is_universal' => false,
        'compatibility_properties' => ['Year' => '2011', 'Make' => 'BMW', 'Model' => '135i'],
        'display_year' => '2011',
        'display_make' => 'BMW',
        'display_model' => '135i',
        'source' => ItemFitment::SOURCE_OPERATOR,
        'confidence' => ItemFitment::CONFIDENCE_SELLER_CONFIRMED,
    ]);

    $asset = MediaAsset::query()->create([
        'disk' => 'local',
        'storage_key' => 'testing/caliper.jpg',
        'original_filename' => 'caliper.jpg',
        'mime_type' => 'image/jpeg',
        'kind' => MediaAsset::KIND_ORIGINAL,
        'metadata' => ['public_url' => 'https://cdn.example.test/caliper.jpg'],
    ]);
    ItemPhoto::query()->create([
        'item_id' => $item->id,
        'media_asset_id' => $asset->id,
        'sort_order' => 1,
    ]);

    CatalogDescription::factory()->create([
        'item_id' => $item->id,
        'body' => 'Used BMW rear brake caliper pair.',
        'is_accepted' => true,
    ]);
}

test('ebay marketplace page is visible to admins', function (): void {
    $user = createAdminUser();

    $this->actingAs($user)
        ->get(route('commerce.marketplace.ebay.index'))
        ->assertOk()
        ->assertSee('eBay Marketplace')
        ->assertSee('Set up the eBay connection in')
        ->assertSee('eBay settings')
        ->assertSee(route('commerce.marketplace.ebay.settings'), false)
        ->assertDontSee('Connect eBay');
});

test('ebay settings can refresh metadata for mapped template categories', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    configureEbayMarketplaceForCompany($user->company_id, ['https://api.ebay.com/oauth/api_scope/sell.inventory']);

    app(OAuthTokenStore::class)->persist(
        EbayConfiguration::CHANNEL,
        Scope::company($user->company_id),
        [
            'access_token' => 'application-token-settings-refresh',
            'expires_in' => 3600,
        ],
        EbayConfiguration::APPLICATION_SCOPES,
        EbayConfiguration::APPLICATION_TOKEN_ACCOUNT_KEY,
        metadata: ['token_kind' => 'application'],
    );

    $template = ProductTemplate::factory()->create([
        'company_id' => $user->company_id,
        'name' => 'Headlight Assembly',
    ]);

    Http::fake([
        'https://api.sandbox.ebay.com/commerce/taxonomy/v1/category_tree/100' => Http::response([
            'categoryTreeId' => '100',
            'rootCategoryNode' => ['category' => ['categoryId' => '6000', 'categoryName' => 'eBay Motors']],
        ]),
        'https://api.sandbox.ebay.com/commerce/taxonomy/v1/category_tree/100/get_category_subtree*' => Http::response([
            'categorySubtreeNode' => ['category' => ['categoryId' => '33710', 'categoryName' => 'Headlight Assemblies']],
        ]),
        'https://api.sandbox.ebay.com/commerce/taxonomy/v1/category_tree/100/get_item_aspects_for_category*' => Http::response([
            'aspects' => [['localizedAspectName' => 'Brand']],
        ]),
        'https://api.sandbox.ebay.com/commerce/taxonomy/v1/category_tree/100/get_compatibility_properties*' => Http::response([
            'compatibilityProperties' => [['name' => 'Year'], ['name' => 'Make']],
        ]),
        'https://api.sandbox.ebay.com/sell/metadata/v1/marketplace/EBAY_MOTORS_US/get_automotive_parts_compatibility_policies*' => Http::response([
            'automotivePartsCompatibilityPolicies' => [['categoryId' => '33710', 'compatibilityBasedOn' => 'ASSEMBLY']],
        ]),
        'https://api.sandbox.ebay.com/sell/metadata/v1/marketplace/EBAY_MOTORS_US/get_item_condition_policies*' => Http::response([
            'itemConditionPolicies' => [['categoryId' => '33710', 'itemConditions' => [['conditionId' => '3000']]]],
        ]),
    ]);

    Livewire::test(EbaySettings::class)
        ->set("templateCategoryMappings.{$template->id}.marketplace_id", 'EBAY_MOTORS_US')
        ->set("templateCategoryMappings.{$template->id}.category_tree_id", '100')
        ->set("templateCategoryMappings.{$template->id}.category_id", '33710')
        ->call('refreshMappedCategoryMetadata')
        ->assertHasNoErrors();

    expect(MarketplaceMetadata::query()->where('kind', EbayMetadataService::KIND_CATEGORY_TREE)->where('key', '100')->exists())->toBeTrue()
        ->and(MarketplaceMetadata::query()->where('kind', EbayMetadataService::KIND_CATEGORY_SUBTREE)->where('key', '100:33710')->exists())->toBeTrue()
        ->and(MarketplaceMetadata::query()->where('kind', EbayMetadataService::KIND_CATEGORY_ASPECTS)->where('key', '100:33710')->exists())->toBeTrue()
        ->and(MarketplaceMetadata::query()->where('kind', EbayMetadataService::KIND_COMPATIBILITY_PROPERTIES)->where('key', '100:33710')->exists())->toBeTrue()
        ->and(MarketplaceMetadata::query()->where('kind', EbayMetadataService::KIND_AUTOMOTIVE_PARTS_COMPATIBILITY_POLICIES)->where('key', '33710')->exists())->toBeTrue()
        ->and(MarketplaceMetadata::query()->where('kind', EbayMetadataService::KIND_ITEM_CONDITION_POLICIES)->where('key', '33710')->exists())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.sandbox.ebay.com/commerce/taxonomy/v1/category_tree/100/get_item_aspects_for_category')
        && $request->hasHeader('Authorization', 'Bearer application-token-settings-refresh')
        && $request['category_id'] === '33710');
});

test('ebay settings default template mappings from commerce plugins', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    configureEbayMarketplaceForCompany($user->company_id, []);

    app(CommercePluginRegistry::class)->registerMarketplaceTemplateMapping([
        'id' => 'test.ebay.settings-template',
        'channel' => EbayConfiguration::CHANNEL,
        'template_code' => 'settings-headlight',
        'marketplace_id' => 'EBAY_MOTORS_US',
        'category_tree_id' => '100',
        'category_id' => '33710',
    ]);

    $template = ProductTemplate::factory()->create([
        'company_id' => $user->company_id,
        'code' => 'settings-headlight',
        'metadata' => null,
    ]);

    Livewire::test(EbaySettings::class)
        ->assertSet("templateCategoryMappings.{$template->id}.marketplace_id", 'EBAY_MOTORS_US')
        ->assertSet("templateCategoryMappings.{$template->id}.category_tree_id", '100')
        ->assertSet("templateCategoryMappings.{$template->id}.category_id", '33710');
});

test('ebay marketplace surfaces imported listing audit states', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $sharedTemplate = ProductTemplate::factory()->create([
        'company_id' => $user->company_id,
    ]);

    $readyItem = Item::factory()->create([
        'company_id' => $user->company_id,
        'product_template_id' => $sharedTemplate->id,
        'sku' => 'AUDIT-READY-1',
        'status' => Item::STATUS_LISTED,
        'target_price_amount' => 12000,
        'currency_code' => 'USD',
    ]);
    $fitmentItem = Item::factory()->create([
        'company_id' => $user->company_id,
        'product_template_id' => $sharedTemplate->id,
        'sku' => 'AUDIT-FITMENT-1',
        'status' => Item::STATUS_READY,
        'target_price_amount' => 13000,
        'currency_code' => 'USD',
    ]);
    $identifierItem = Item::factory()->create([
        'company_id' => $user->company_id,
        'product_template_id' => $sharedTemplate->id,
        'sku' => 'AUDIT-ID-1',
        'status' => Item::STATUS_READY,
        'target_price_amount' => 14000,
        'currency_code' => 'USD',
    ]);
    $managedItem = Item::factory()->create([
        'company_id' => $user->company_id,
        'product_template_id' => $sharedTemplate->id,
        'sku' => 'AUDIT-MANAGED-1',
        'status' => Item::STATUS_LISTED,
        'target_price_amount' => 15000,
        'currency_code' => 'USD',
    ]);
    $legacyItem = Item::factory()->create([
        'company_id' => $user->company_id,
        'product_template_id' => $sharedTemplate->id,
        'sku' => 'AUDIT-LEGACY-1',
        'status' => Item::STATUS_READY,
        'target_price_amount' => 15500,
        'currency_code' => 'USD',
    ]);
    $conflictItem = Item::factory()->create([
        'company_id' => $user->company_id,
        'product_template_id' => $sharedTemplate->id,
        'sku' => 'AUDIT-CONFLICT-1',
        'status' => Item::STATUS_READY,
        'target_price_amount' => 16000,
        'currency_code' => 'USD',
    ]);
    $fitmentSourceItem = Item::factory()->create([
        'company_id' => $user->company_id,
        'product_template_id' => $sharedTemplate->id,
        'sku' => 'FIT-SOURCE-1',
        'title' => 'BMW donor vehicle set',
        'status' => Item::STATUS_READY,
    ]);
    Item::factory()->create([
        'company_id' => $user->company_id,
        'product_template_id' => $sharedTemplate->id,
        'sku' => 'FIT-TARGET-1',
        'title' => 'BMW spare dust shield',
        'status' => Item::STATUS_READY,
    ]);

    $legacyItem->forceFill([
        'created_at' => now()->subDays(60),
        'updated_at' => now()->subDays(60),
    ])->saveQuietly();

    ItemFitment::query()->create([
        'company_id' => $user->company_id,
        'item_id' => $fitmentSourceItem->id,
        'compatibility_properties' => ['Year' => '2011', 'Make' => 'BMW', 'Model' => '135i'],
        'display_year' => '2011',
        'display_make' => 'BMW',
        'display_model' => '135i',
        'source' => ItemFitment::SOURCE_OPERATOR,
        'confidence' => ItemFitment::CONFIDENCE_SELLER_CONFIRMED,
    ]);

    $readyListing = Listing::query()->create([
        'company_id' => $user->company_id,
        'item_id' => $readyItem->id,
        'channel' => EbayConfiguration::CHANNEL,
        'external_listing_id' => 'audit-ready-1',
        'external_offer_id' => 'offer-audit-ready-1',
        'external_sku' => $readyItem->sku,
        'marketplace_id' => 'EBAY_US',
        'title' => 'Ready to adopt caliper',
        'status' => 'ACTIVE',
        'management_state' => Listing::MANAGEMENT_IMPORTED,
        'drift_status' => Listing::DRIFT_UNKNOWN,
        'price_amount' => 12000,
        'currency_code' => 'USD',
        'last_synced_at' => now(),
        'raw_payload' => ['inventory_item' => ['sku' => $readyItem->sku]],
    ]);
    $fitmentListing = Listing::query()->create([
        'company_id' => $user->company_id,
        'item_id' => $fitmentItem->id,
        'channel' => EbayConfiguration::CHANNEL,
        'external_listing_id' => 'audit-fitment-1',
        'external_offer_id' => 'offer-audit-fitment-1',
        'external_sku' => $fitmentItem->sku,
        'marketplace_id' => 'EBAY_US',
        'title' => 'Missing fitment rotor',
        'status' => 'ACTIVE',
        'management_state' => Listing::MANAGEMENT_IMPORTED,
        'drift_status' => Listing::DRIFT_UNKNOWN,
        'price_amount' => 13000,
        'currency_code' => 'USD',
        'last_synced_at' => now(),
        'raw_payload' => ['inventory_item' => ['sku' => $fitmentItem->sku]],
    ]);
    $identifierListing = Listing::query()->create([
        'company_id' => $user->company_id,
        'item_id' => $identifierItem->id,
        'channel' => EbayConfiguration::CHANNEL,
        'external_listing_id' => 'audit-id-1',
        'external_offer_id' => 'offer-audit-id-1',
        'external_sku' => $identifierItem->sku,
        'marketplace_id' => 'EBAY_US',
        'title' => 'Missing identifiers mirror',
        'status' => 'ACTIVE',
        'management_state' => Listing::MANAGEMENT_IMPORTED,
        'drift_status' => Listing::DRIFT_UNKNOWN,
        'price_amount' => 14000,
        'currency_code' => 'USD',
        'last_synced_at' => now(),
        'raw_payload' => ['inventory_item' => ['sku' => $identifierItem->sku]],
    ]);
    $managedListing = Listing::query()->create([
        'company_id' => $user->company_id,
        'item_id' => $managedItem->id,
        'channel' => EbayConfiguration::CHANNEL,
        'external_listing_id' => 'audit-managed-1',
        'external_offer_id' => 'offer-audit-managed-1',
        'external_sku' => $managedItem->sku,
        'marketplace_id' => 'EBAY_US',
        'title' => 'Externally changed headlight',
        'status' => 'ACTIVE',
        'management_state' => Listing::MANAGEMENT_BELIMBING_MANAGED,
        'drift_status' => Listing::DRIFT_DRIFTED,
        'drift_summary' => 'Externally changed: price.',
        'price_amount' => 15500,
        'currency_code' => 'USD',
        'last_synced_at' => now(),
        'raw_payload' => [],
    ]);
    $legacyListing = Listing::query()->create([
        'company_id' => $user->company_id,
        'item_id' => $legacyItem->id,
        'channel' => EbayConfiguration::CHANNEL,
        'external_listing_id' => 'audit-legacy-1',
        'external_offer_id' => null,
        'external_sku' => $legacyItem->sku,
        'marketplace_id' => 'EBAY_US',
        'title' => 'Legacy relist bumper cover',
        'status' => 'ACTIVE',
        'management_state' => Listing::MANAGEMENT_IMPORTED,
        'drift_status' => Listing::DRIFT_UNKNOWN,
        'price_amount' => 15500,
        'currency_code' => 'USD',
        'last_synced_at' => now(),
        'raw_payload' => [],
    ]);
    $conflictListing = Listing::query()->create([
        'company_id' => $user->company_id,
        'item_id' => $conflictItem->id,
        'channel' => EbayConfiguration::CHANNEL,
        'external_listing_id' => 'audit-conflict-1',
        'external_offer_id' => 'offer-audit-conflict-1',
        'external_sku' => $conflictItem->sku,
        'marketplace_id' => 'EBAY_US',
        'title' => 'Conflicting identifiers caliper',
        'status' => 'ACTIVE',
        'management_state' => Listing::MANAGEMENT_IMPORTED,
        'drift_status' => Listing::DRIFT_UNKNOWN,
        'price_amount' => 16000,
        'currency_code' => 'USD',
        'last_synced_at' => now(),
        'raw_payload' => ['inventory_item' => ['sku' => $conflictItem->sku]],
    ]);

    foreach ([
        [$readyItem, $readyListing, [], []],
        [$fitmentItem, $fitmentListing, [['key' => 'fitment', 'label' => 'Add fitment.', 'action' => 'fitment']], []],
        [$identifierItem, $identifierListing, [], [['key' => 'identifier_part_number', 'label' => 'Add part number.', 'action' => 'attributes']]],
        [$managedItem, $managedListing, [], []],
        [$legacyItem, $legacyListing, [], []],
        [$conflictItem, $conflictListing, [], [['key' => 'identifier_conflict', 'label' => 'Review conflict.', 'action' => 'attributes']]],
    ] as [$item, $listing, $blockers, $warnings]) {
        ListingDraft::query()->create([
            'company_id' => $user->company_id,
            'item_id' => $item->id,
            'listing_id' => $listing->id,
            'channel' => EbayConfiguration::CHANNEL,
            'marketplace_id' => 'EBAY_US',
            'metadata_marketplace_id' => 'EBAY_MOTORS_US',
            'external_sku' => $item->sku,
            'title' => $listing->title,
            'status' => $listing->isBelimbingManaged() ? ListingDraft::STATUS_PUBLISHED : ListingDraft::STATUS_IMPORTED,
            'management_state' => $listing->management_state,
            'readiness_status' => $blockers === [] ? 'ready' : 'blocked',
            'readiness_snapshot' => [
                'blockers' => $blockers,
                'warnings' => $warnings,
                'identifier_alignment' => $listing->external_listing_id === 'audit-conflict-1'
                    ? [[
                        'key' => 'part_number',
                        'label' => 'Part Number',
                        'status' => 'conflict',
                        'sources' => [
                            'ebay_listing' => ['34206785237'],
                            'ebay_product_reference' => ['34206785238'],
                        ],
                    ]]
                    : [],
            ],
        ]);
    }

    $order = Order::query()->create([
        'company_id' => $user->company_id,
        'channel' => EbayConfiguration::CHANNEL,
        'external_order_id' => 'ORDER-AUDIT-1',
        'marketplace_id' => 'EBAY_US',
        'buyer_username' => 'bmw-buyer',
        'status' => 'CANCELLED',
        'ordered_at' => now()->subDays(2),
        'last_synced_at' => now()->subDay(),
        'raw_payload' => [
            'buyerCheckoutNotes' => 'Will this fit my 2011 BMW 135i?',
            'cancelStatus' => 'CANCELLED_BY_BUYER',
        ],
    ]);
    OrderLine::query()->create([
        'company_id' => $user->company_id,
        'order_id' => $order->id,
        'item_id' => $legacyItem->id,
        'listing_id' => $legacyListing->id,
        'external_line_item_id' => 'ORDER-AUDIT-1-L1',
        'external_listing_id' => $legacyListing->external_listing_id,
        'external_sku' => $legacyItem->sku,
        'title' => $legacyListing->title,
        'quantity' => 1,
        'line_total_amount' => 15500,
        'currency_code' => 'USD',
        'raw_payload' => [],
    ]);

    Livewire::actingAs($user)
        ->test(MarketplaceIndex::class)
        ->assertSee('Store Progress')
        ->assertSee('Cleanup Queue')
        ->assertSee('Trust Signals')
        ->assertSee('Fitment Reuse')
        ->assertSee('Ready to Adopt')
        ->assertSee('Missing Fitment')
        ->assertSee('Conflicting Identifiers')
        ->assertSee('Legacy Relist Required')
        ->assertSee('Missing Identifiers')
        ->assertSee('Externally Changed')
        ->assertSee('Conflicting IDs')
        ->assertSee('Relist Required')
        ->assertSee('Buyer question')
        ->assertSee('Return / cancellation signal')
        ->assertSee('Review pricing, title, and fitment for aging stock with no sale yet.')
        ->assertSee('Copy fitment from FIT-SOURCE-1 (1 entries).')
        ->assertSee('Linked')
        ->assertSee('Missing fitment rotor')
        ->assertSee('Externally changed headlight')
        ->assertSee('Legacy relist bumper cover')
        ->assertSee('Conflicting identifiers caliper');
});

test('ebay listing pull materializes offers and links by sku', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    configureEbayMarketplaceForCompany(
        $user->company_id,
        ['https://api.ebay.com/oauth/api_scope/sell.inventory'],
    );

    $item = Item::factory()->create([
        'company_id' => $user->company_id,
        'sku' => EBAY_FIXTURE_SKU,
    ]);
    seedReadyEbayListingInputs($item, $user->company_id);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/inventory/v1/inventory_item*' => Http::response([
            'total' => 1,
            'inventoryItems' => [
                [
                    'sku' => EBAY_FIXTURE_SKU,
                    'product' => [
                        'title' => EBAY_FIXTURE_TITLE,
                        'epid' => '1122066940',
                        'aspects' => ['Brand' => ['Honda']],
                    ],
                ],
            ],
        ]),
        'https://api.sandbox.ebay.com/sell/inventory/v1/offer*' => Http::response([
            'offers' => [
                [
                    'offerId' => 'offer-1',
                    'sku' => EBAY_FIXTURE_SKU,
                    'marketplaceId' => 'EBAY_US',
                    'status' => 'PUBLISHED',
                    'listing' => [
                        'listingId' => EBAY_FIXTURE_LISTING_ID,
                        'listingStatus' => 'ACTIVE',
                    ],
                    'pricingSummary' => [
                        'price' => [
                            'currency' => 'USD',
                            'value' => EBAY_FIXTURE_PRICE_DECIMAL,
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $result = app(EbayMarketplaceChannel::class)->pullListings($user->company_id);

    $listing = Listing::query()->where('external_listing_id', EBAY_FIXTURE_LISTING_ID)->first();
    $reference = ProductReference::query()->where('external_product_id', '1122066940')->first();
    $draft = ListingDraft::query()
        ->where('item_id', $item->id)
        ->where('listing_id', $listing?->id)
        ->latest('updated_at')
        ->first();

    expect($result->fetched)->toBe(1)
        ->and($result->created)->toBe(1)
        ->and($result->linked)->toBe(1)
        ->and($listing)->not()->toBeNull()
        ->and($listing->item_id)->toBe($item->id)
        ->and($listing->price_amount)->toBe(12000)
        ->and($listing->currency_code)->toBe('USD')
        ->and($draft)->not()->toBeNull()
        ->and($draft->management_state)->toBe('imported')
        ->and($draft->status)->toBe('imported')
        ->and($draft->aspect_values['Brand']['value'])->toBe(['Honda'])
        ->and(data_get($draft->readiness_snapshot, 'facts.inventory_api_visible'))->toBeTrue()
        ->and(data_get($draft->readiness_snapshot, 'facts.inventory_api_writable'))->toBeTrue()
        ->and(data_get($draft->readiness_snapshot, 'facts.adoption_state'))->toBe(Listing::ADOPTION_INVENTORY_API_ADOPTABLE)
        ->and($reference)->not()->toBeNull()
        ->and($reference->item_id)->toBe($item->id)
        ->and($reference->listing_draft_id)->toBe($draft->id)
        ->and($reference->reference_type)->toBe(ProductReference::TYPE_EBAY_EPID)
        ->and($reference->facts['aspects']['Brand'])->toBe(['Honda']);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sell/inventory/v1/offer')
        && $request->hasHeader('X-EBAY-C-MARKETPLACE-ID', 'EBAY_US'));
});

test('ebay listing pull refreshes existing belimbing-managed draft linkage and product references', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    configureEbayMarketplaceForCompany(
        $user->company_id,
        ['https://api.ebay.com/oauth/api_scope/sell.inventory'],
    );

    $item = Item::factory()->create([
        'company_id' => $user->company_id,
        'sku' => 'BMW-CALIPER-IMPORT-1',
        'status' => Item::STATUS_LISTED,
    ]);
    seedReadyEbayListingInputs($item, $user->company_id);

    $listing = Listing::query()->create([
        'company_id' => $user->company_id,
        'item_id' => $item->id,
        'channel' => EbayConfiguration::CHANNEL,
        'external_listing_id' => '4455667788',
        'external_offer_id' => 'offer-import-1',
        'external_sku' => 'BMW-CALIPER-IMPORT-1',
        'marketplace_id' => 'EBAY_US',
        'title' => 'BMW rear brake caliper pair',
        'status' => 'ACTIVE',
        'management_state' => 'belimbing_managed',
        'drift_status' => 'in_sync',
        'price_amount' => 25000,
        'currency_code' => 'USD',
        'raw_payload' => [
            'publish_contract' => [
                'inventory_item' => [
                    'product' => [
                        'title' => 'BMW rear brake caliper pair',
                    ],
                ],
                'offer' => [
                    'availableQuantity' => 1,
                    'pricingSummary' => [
                        'price' => [
                            'value' => '250.00',
                            'currency' => 'USD',
                        ],
                    ],
                ],
            ],
        ],
        'last_synced_at' => now()->subDay(),
    ]);

    $existingDraft = ListingDraft::query()->create([
        'company_id' => $user->company_id,
        'item_id' => $item->id,
        'listing_id' => $listing->id,
        'channel' => EbayConfiguration::CHANNEL,
        'marketplace_id' => 'EBAY_US',
        'metadata_marketplace_id' => 'EBAY_MOTORS_US',
        'external_sku' => 'BMW-CALIPER-IMPORT-1',
        'title' => 'BMW rear brake caliper pair',
        'status' => 'published',
        'management_state' => 'belimbing_managed',
        'readiness_status' => 'ready',
    ]);

    ProductReference::query()->create([
        'company_id' => $user->company_id,
        'item_id' => $item->id,
        'listing_id' => $listing->id,
        'listing_draft_id' => $existingDraft->id,
        'channel' => EbayConfiguration::CHANNEL,
        'marketplace_id' => 'EBAY_US',
        'reference_type' => ProductReference::TYPE_EBAY_EPID,
        'external_product_id' => '1122066940',
        'title' => 'BMW brake caliper',
        'facts' => ['aspects' => ['Brand' => ['BMW']]],
        'source' => ProductReference::SOURCE_IMPORTED,
        'review_status' => ProductReference::REVIEW_SUGGESTED,
        'imported_at' => Carbon::now()->subDay(),
    ]);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/inventory/v1/inventory_item*' => Http::response([
            'total' => 1,
            'inventoryItems' => [
                [
                    'sku' => 'BMW-CALIPER-IMPORT-1',
                    'product' => [
                        'title' => 'BMW rear brake caliper pair',
                        'epid' => '1122066940',
                        'aspects' => [
                            'Brand' => ['BMW'],
                            'Manufacturer Part Number' => ['34206785237'],
                        ],
                    ],
                ],
            ],
        ]),
        'https://api.sandbox.ebay.com/sell/inventory/v1/offer*' => Http::response([
            'offers' => [
                [
                    'offerId' => 'offer-import-1',
                    'sku' => 'BMW-CALIPER-IMPORT-1',
                    'marketplaceId' => 'EBAY_US',
                    'status' => 'PUBLISHED',
                    'listing' => [
                        'listingId' => '4455667788',
                        'listingStatus' => 'ACTIVE',
                    ],
                    'pricingSummary' => [
                        'price' => [
                            'currency' => 'USD',
                            'value' => '250.00',
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    app(EbayMarketplaceChannel::class)->pullListings($user->company_id);

    $draft = ListingDraft::query()
        ->where('item_id', $item->id)
        ->where('listing_id', $listing->id)
        ->latest('updated_at')
        ->firstOrFail();
    $reference = ProductReference::query()
        ->where('listing_id', $listing->id)
        ->where('external_product_id', '1122066940')
        ->firstOrFail();

    expect($draft->id)->toBe($existingDraft->id)
        ->and($draft->management_state)->toBe('belimbing_managed')
        ->and($draft->status)->toBe('published')
        ->and($draft->aspect_values['Manufacturer Part Number']['value'])->toBe(['34206785237'])
        ->and(data_get($draft->readiness_snapshot, 'facts.inventory_api_visible'))->toBeTrue()
        ->and(data_get($draft->readiness_snapshot, 'facts.inventory_api_writable'))->toBeTrue()
        ->and(data_get($draft->readiness_snapshot, 'facts.adoption_state'))->toBe(Listing::ADOPTION_INVENTORY_API_ADOPTABLE)
        ->and($reference->listing_draft_id)->toBe($draft->id)
        ->and($reference->target_key)->toBe('draft:'.$draft->id);
});

test('ebay registers as a marketplace channel provider', function (): void {
    $descriptor = app(MarketplaceChannelRegistry::class)->descriptor(EbayConfiguration::CHANNEL);

    expect($descriptor->key)->toBe(EbayConfiguration::CHANNEL)
        ->and($descriptor->label)->toBe('eBay')
        ->and($descriptor->settingsGroup)->toBe('marketplace_ebay')
        ->and($descriptor->supports('pull_listings'))->toBeTrue()
        ->and($descriptor->supports('create_listing'))->toBeTrue()
        ->and(app(MarketplaceChannelRegistry::class)->channel(EbayConfiguration::CHANNEL))
        ->toBeInstanceOf(EbayMarketplaceChannel::class);
});

test('ebay publish creates inventory compatibility offer and listing records', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    configureEbayMarketplaceForCompany(
        $user->company_id,
        ['https://api.ebay.com/oauth/api_scope/sell.inventory'],
    );

    $item = Item::factory()->create([
        'company_id' => $user->company_id,
        'sku' => 'BMW-CALIPER-0001',
    ]);

    seedReadyEbayListingInputs($item, $user->company_id);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/inventory/v1/inventory_item/*/product_compatibility' => Http::response([], 204),
        'https://api.sandbox.ebay.com/sell/inventory/v1/inventory_item/*' => Http::response([], 204),
        'https://api.sandbox.ebay.com/sell/inventory/v1/offer' => Http::response([
            'offerId' => 'offer-publish-1',
        ], 201),
        'https://api.sandbox.ebay.com/sell/inventory/v1/offer/offer-publish-1/publish' => Http::response([
            'listingId' => '9988776655',
        ], 200),
    ]);

    $result = app(EbayMarketplaceChannel::class)->createListing($item->fresh());

    $listing = Listing::query()->where('external_listing_id', '9988776655')->firstOrFail();
    $latestDraft = ListingDraft::query()
        ->where('item_id', $item->id)
        ->latest('updated_at')
        ->firstOrFail();

    expect($result['external_listing_id'])->toBe('9988776655')
        ->and($result['external_offer_id'])->toBe('offer-publish-1')
        ->and($listing->marketplace_id)->toBe('EBAY_US')
        ->and($listing->status)->toBe('ACTIVE')
        ->and($listing->management_state)->toBe('belimbing_managed')
        ->and($listing->drift_status)->toBe('in_sync')
        ->and($item->fresh()->status)->toBe(Item::STATUS_LISTED)
        ->and($latestDraft->marketplace_id)->toBe('EBAY_US')
        ->and($latestDraft->metadata_marketplace_id)->toBe('EBAY_MOTORS_US')
        ->and($latestDraft->management_state)->toBe('belimbing_managed')
        ->and(collect($listing->raw_payload['operations'] ?? [])->pluck('name')->all())->toBe([
            'inventory_item_upsert',
            'compatibility_upsert',
            'offer_create',
            'offer_publish',
        ]);

    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'PUT' || ! str_contains($request->url(), '/inventory_item/BMW-CALIPER-0001')) {
            return false;
        }

        $payload = $request->data();

        return ($payload['product']['aspects']['Brand'] ?? null) === ['BMW']
            && ($payload['condition'] ?? null) === 'Used';
    });

    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'PUT' || ! str_contains($request->url(), '/product_compatibility')) {
            return false;
        }

        $payload = $request->data();

        return ($payload['compatibleProducts'][0]['compatibilityProperties'] ?? null) === [
            ['name' => 'year', 'value' => '2011'],
            ['name' => 'make', 'value' => 'BMW'],
            ['name' => 'model', 'value' => '135i'],
        ];
    });

    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'POST' || $request->url() !== 'https://api.sandbox.ebay.com/sell/inventory/v1/offer') {
            return false;
        }

        $payload = $request->data();

        return ($payload['marketplaceId'] ?? null) === 'EBAY_US'
            && ($payload['categoryId'] ?? null) === '33563';
    });
});

test('ebay revise updates a published offer without republishing', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    configureEbayMarketplaceForCompany(
        $user->company_id,
        ['https://api.ebay.com/oauth/api_scope/sell.inventory'],
    );

    $item = Item::factory()->create([
        'company_id' => $user->company_id,
        'sku' => 'BMW-CALIPER-0002',
        'status' => Item::STATUS_LISTED,
    ]);

    seedReadyEbayListingInputs($item, $user->company_id);

    $listing = Listing::query()->create([
        'company_id' => $user->company_id,
        'item_id' => $item->id,
        'channel' => EbayConfiguration::CHANNEL,
        'external_listing_id' => '1122334455',
        'external_offer_id' => 'offer-revise-1',
        'external_sku' => 'BMW-CALIPER-0002',
        'marketplace_id' => 'EBAY_US',
        'title' => 'BMW rear brake caliper pair',
        'status' => 'ACTIVE',
        'management_state' => 'belimbing_managed',
        'drift_status' => 'in_sync',
        'price_amount' => 25000,
        'currency_code' => 'USD',
        'listed_at' => now()->subDay(),
        'last_synced_at' => now()->subDay(),
    ]);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/inventory/v1/inventory_item/*/product_compatibility' => Http::response([], 204),
        'https://api.sandbox.ebay.com/sell/inventory/v1/inventory_item/*' => Http::response([], 204),
        'https://api.sandbox.ebay.com/sell/inventory/v1/offer/offer-revise-1' => Http::response([], 204),
    ]);

    $result = app(EbayMarketplaceChannel::class)->reviseListing($listing->fresh());

    $listing->refresh();

    expect($result['external_offer_id'])->toBe('offer-revise-1')
        ->and($listing->status)->toBe('ACTIVE')
        ->and($listing->management_state)->toBe('belimbing_managed')
        ->and($listing->drift_status)->toBe('in_sync')
        ->and(collect($listing->raw_payload['operations'] ?? [])->pluck('name')->all())->toBe([
            'inventory_item_upsert',
            'compatibility_upsert',
            'offer_update',
        ]);

    Http::assertSentCount(3);
});

test('ebay withdraw ends a published offer and returns the item to ready state', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    configureEbayMarketplaceForCompany(
        $user->company_id,
        ['https://api.ebay.com/oauth/api_scope/sell.inventory'],
    );

    $item = Item::factory()->create([
        'company_id' => $user->company_id,
        'sku' => 'BMW-CALIPER-0003',
        'status' => Item::STATUS_LISTED,
    ]);

    $listing = Listing::query()->create([
        'company_id' => $user->company_id,
        'item_id' => $item->id,
        'channel' => EbayConfiguration::CHANNEL,
        'external_listing_id' => '6677889900',
        'external_offer_id' => 'offer-withdraw-1',
        'external_sku' => 'BMW-CALIPER-0003',
        'marketplace_id' => 'EBAY_US',
        'title' => 'BMW rear brake caliper pair',
        'status' => 'ACTIVE',
        'management_state' => 'belimbing_managed',
        'drift_status' => 'in_sync',
        'price_amount' => 25000,
        'currency_code' => 'USD',
        'listed_at' => now()->subDay(),
        'last_synced_at' => now()->subDay(),
    ]);

    ListingDraft::query()->create([
        'company_id' => $user->company_id,
        'item_id' => $item->id,
        'listing_id' => $listing->id,
        'channel' => EbayConfiguration::CHANNEL,
        'marketplace_id' => 'EBAY_US',
        'metadata_marketplace_id' => 'EBAY_MOTORS_US',
        'external_sku' => 'BMW-CALIPER-0003',
        'title' => 'BMW rear brake caliper pair',
        'status' => 'published',
        'management_state' => 'belimbing_managed',
        'readiness_status' => 'ready',
    ]);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/inventory/v1/offer/offer-withdraw-1/withdraw' => Http::response([
            'listingId' => '6677889900',
        ], 200),
    ]);

    $result = app(EbayMarketplaceChannel::class)->endListing($listing->fresh());

    $listing->refresh();
    $item->refresh();
    $draft = ListingDraft::query()
        ->where('item_id', $item->id)
        ->latest('updated_at')
        ->firstOrFail();

    expect($result['external_offer_id'])->toBe('offer-withdraw-1')
        ->and($listing->status)->toBe('UNPUBLISHED')
        ->and($listing->ended_at)->not()->toBeNull()
        ->and($item->status)->toBe(Item::STATUS_READY)
        ->and($draft->status)->toBe('withdrawn');
});

test('ebay listing pull marks belimbing-managed listings as drifted when ebay changed them externally', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    configureEbayMarketplaceForCompany(
        $user->company_id,
        ['https://api.ebay.com/oauth/api_scope/sell.inventory'],
    );

    $item = Item::factory()->create([
        'company_id' => $user->company_id,
        'sku' => EBAY_FIXTURE_SKU,
        'title' => EBAY_FIXTURE_TITLE,
        'status' => Item::STATUS_LISTED,
        'target_price_amount' => 12000,
        'currency_code' => 'USD',
    ]);

    Listing::query()->create([
        'company_id' => $user->company_id,
        'item_id' => $item->id,
        'channel' => EbayConfiguration::CHANNEL,
        'external_listing_id' => EBAY_FIXTURE_LISTING_ID,
        'external_offer_id' => 'offer-1',
        'external_sku' => EBAY_FIXTURE_SKU,
        'marketplace_id' => 'EBAY_US',
        'title' => EBAY_FIXTURE_TITLE,
        'status' => 'ACTIVE',
        'management_state' => 'belimbing_managed',
        'drift_status' => 'in_sync',
        'price_amount' => 12000,
        'currency_code' => 'USD',
        'raw_payload' => [
            'publish_contract' => [
                'inventory_item' => [
                    'product' => [
                        'title' => EBAY_FIXTURE_TITLE,
                    ],
                ],
                'offer' => [
                    'availableQuantity' => 1,
                    'pricingSummary' => [
                        'price' => [
                            'value' => '120.00',
                            'currency' => 'USD',
                        ],
                    ],
                ],
            ],
        ],
        'last_synced_at' => now()->subDay(),
    ]);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/inventory/v1/inventory_item*' => Http::response([
            'total' => 1,
            'inventoryItems' => [
                [
                    'sku' => EBAY_FIXTURE_SKU,
                    'product' => [
                        'title' => 'Changed on eBay',
                    ],
                    'availability' => [
                        'shipToLocationAvailability' => [
                            'quantity' => 3,
                        ],
                    ],
                ],
            ],
        ]),
        'https://api.sandbox.ebay.com/sell/inventory/v1/offer*' => Http::response([
            'offers' => [
                [
                    'offerId' => 'offer-1',
                    'sku' => EBAY_FIXTURE_SKU,
                    'marketplaceId' => 'EBAY_US',
                    'status' => 'PUBLISHED',
                    'availableQuantity' => 3,
                    'listing' => [
                        'listingId' => EBAY_FIXTURE_LISTING_ID,
                        'listingStatus' => 'ACTIVE',
                    ],
                    'pricingSummary' => [
                        'price' => [
                            'currency' => 'USD',
                            'value' => '130.00',
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    app(EbayMarketplaceChannel::class)->pullListings($user->company_id);

    $listing = Listing::query()->where('external_listing_id', EBAY_FIXTURE_LISTING_ID)->firstOrFail();

    expect($listing->management_state)->toBe('belimbing_managed')
        ->and($listing->drift_status)->toBe('drifted')
        ->and($listing->drift_summary)->toContain('title')
        ->and($listing->drift_summary)->toContain('price')
        ->and($listing->drift_summary)->toContain('quantity');

    $this->get(route('commerce.marketplace.ebay.index'))
        ->assertSee('Externally Changed')
        ->assertSee('Externally changed: title, price, quantity.');
});

test('ebay order pull materializes sales ledger rows and links inventory', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    configureEbayMarketplaceForCompany(
        $user->company_id,
        ['https://api.ebay.com/oauth/api_scope/sell.fulfillment'],
    );

    $item = Item::factory()->create([
        'company_id' => $user->company_id,
        'sku' => EBAY_FIXTURE_SKU,
        'status' => Item::STATUS_LISTED,
        'unit_cost_amount' => 4000,
    ]);

    Listing::query()->create([
        'company_id' => $user->company_id,
        'item_id' => $item->id,
        'channel' => EbayConfiguration::CHANNEL,
        'external_listing_id' => EBAY_FIXTURE_LISTING_ID,
        'external_offer_id' => 'offer-1',
        'external_sku' => EBAY_FIXTURE_SKU,
        'marketplace_id' => 'EBAY_US',
        'title' => EBAY_FIXTURE_TITLE,
        'status' => 'ACTIVE',
        'price_amount' => 12000,
        'currency_code' => 'USD',
        'last_synced_at' => now(),
    ]);

    Http::fake([
        'https://api.sandbox.ebay.com/sell/fulfillment/v1/order*' => Http::response([
            'total' => 1,
            'orders' => [
                [
                    'orderId' => '27-12345-67890',
                    'creationDate' => '2026-04-20T12:34:56.000Z',
                    'lastModifiedDate' => '2026-04-21T12:34:56.000Z',
                    'orderPaymentStatus' => 'PAID',
                    'orderFulfillmentStatus' => 'FULFILLED',
                    'buyer' => [
                        'username' => 'buyer-one',
                        'email' => 'buyer@example.test',
                    ],
                    'pricingSummary' => [
                        'total' => [
                            'currency' => 'USD',
                            'value' => '135.00',
                        ],
                    ],
                    'paymentSummary' => [
                        'payments' => [
                            [
                                'paymentDate' => '2026-04-20T12:40:00.000Z',
                            ],
                        ],
                    ],
                    'lineItems' => [
                        [
                            'lineItemId' => '1001',
                            'legacyItemId' => EBAY_FIXTURE_LISTING_ID,
                            'sku' => EBAY_FIXTURE_SKU,
                            'title' => EBAY_FIXTURE_TITLE,
                            'quantity' => 1,
                            'listingMarketplaceId' => 'EBAY_US',
                            'lineItemCost' => [
                                'currency' => 'USD',
                                'value' => EBAY_FIXTURE_PRICE_DECIMAL,
                            ],
                            'total' => [
                                'currency' => 'USD',
                                'value' => EBAY_FIXTURE_PRICE_DECIMAL,
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $result = app(EbayMarketplaceChannel::class)->pullOrders($user->company_id);

    $order = Order::query()->where('external_order_id', '27-12345-67890')->first();
    $line = OrderLine::query()->where('external_line_item_id', '1001')->first();
    $sale = Sale::query()->where('external_sale_id', '27-12345-67890:1001')->first();

    expect($result->fetched)->toBe(1)
        ->and($result->created)->toBe(1)
        ->and($result->linked)->toBe(1)
        ->and($order)->not()->toBeNull()
        ->and($order->total_amount)->toBe(13500)
        ->and($line)->not()->toBeNull()
        ->and($line->item_id)->toBe($item->id)
        ->and($line->line_total_amount)->toBe(12000)
        ->and($sale)->not()->toBeNull()
        ->and($sale->item_id)->toBe($item->id)
        ->and($sale->sale_amount)->toBe(12000)
        ->and($sale->cost_basis_amount)->toBe(4000)
        ->and($item->refresh()->status)->toBe(Item::STATUS_SOLD);

    $secondResult = app(EbayMarketplaceChannel::class)->pullOrders($user->company_id);

    expect($secondResult->created)->toBe(0)
        ->and($secondResult->updated)->toBe(1)
        ->and(Order::query()->count())->toBe(1)
        ->and(OrderLine::query()->count())->toBe(1)
        ->and(Sale::query()->count())->toBe(1);
});
