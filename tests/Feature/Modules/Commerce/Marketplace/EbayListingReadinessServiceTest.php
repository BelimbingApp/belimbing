<?php

use App\Base\Integration\Services\OAuthTokenStore;
use App\Base\Media\Models\MediaAsset;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Catalog\Models\Attribute as CatalogAttribute;
use App\Modules\Commerce\Catalog\Models\AttributeValue;
use App\Modules\Commerce\Catalog\Models\Description as CatalogDescription;
use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use App\Modules\Commerce\Inventory\Livewire\Items\Show;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Inventory\Models\ItemFitment;
use App\Modules\Commerce\Inventory\Models\ItemPhoto;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Ebay\EbayListingPayloadBuilder;
use App\Modules\Commerce\Marketplace\Ebay\EbayListingReadinessService;
use App\Modules\Commerce\Marketplace\Ebay\EbayMetadataService;
use App\Modules\Commerce\Marketplace\Models\AccountResource;
use App\Modules\Commerce\Marketplace\Models\AspectMapping;
use App\Modules\Commerce\Marketplace\Models\ListingDraft;
use App\Modules\Commerce\Marketplace\Models\MarketplaceMetadata;
use App\Modules\Commerce\Marketplace\Models\ProductReference;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

test('eBay listing readiness records blockers on the durable draft', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $item = Item::factory()->create([
        'company_id' => $user->company_id,
        'target_price_amount' => null,
        'quantity_on_hand' => 0,
    ]);

    $draft = app(EbayListingReadinessService::class)->refreshForItem($item);

    expect($draft->readiness_status)->toBe(EbayListingReadinessService::STATUS_BLOCKED)
        ->and(collect($draft->readiness_snapshot['blockers'])->pluck('key')->all())->toContain(
            'category',
            'price',
            'quantity',
            'fitment',
            'photos',
            'policy_return',
            'merchant_location',
        );

    Livewire::test(Show::class, ['item' => $item->fresh()])
        ->assertSee('eBay readiness')
        ->assertSee('Map this item template to an eBay category');
});

test('eBay listing readiness uses template mapping policies aspects and product references', function (): void {
    $user = createAdminUser();
    $scope = Scope::company($user->company_id);
    $settings = app(SettingsService::class);
    $settings->set('marketplace.ebay.marketplace_id', 'EBAY_US', $scope);
    $settings->set('marketplace.ebay.default_return_policy_id', 'RET-1', $scope);
    $settings->set('marketplace.ebay.default_fulfillment_policy_id', 'FUL-1', $scope);
    $settings->set('marketplace.ebay.default_payment_policy_id', 'PAY-1', $scope);
    $settings->set('marketplace.ebay.default_merchant_location_key', 'california_shop', $scope);
    app(OAuthTokenStore::class)->persist(
        EbayConfiguration::CHANNEL,
        $scope,
        [
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_in' => 3600,
        ],
        ['https://api.ebay.com/oauth/api_scope/sell.inventory'],
    );

    $template = ProductTemplate::factory()->create([
        'company_id' => $user->company_id,
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
    $item = Item::factory()->create([
        'company_id' => $user->company_id,
        'product_template_id' => $template->id,
        'title' => 'BMW rear brake caliper pair',
        'quantity_on_hand' => 1,
        'target_price_amount' => 25000,
        'currency_code' => 'USD',
    ]);
    $brand = CatalogAttribute::factory()->create([
        'company_id' => $user->company_id,
        'code' => 'brand',
        'name' => 'Brand',
    ]);
    AttributeValue::factory()->create([
        'item_id' => $item->id,
        'attribute_id' => $brand->id,
        'display_value' => 'BMW',
        'value' => ['text' => 'BMW'],
    ]);
    AspectMapping::query()->create([
        'company_id' => $user->company_id,
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
            'company_id' => $user->company_id,
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
        'company_id' => $user->company_id,
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
        'is_accepted' => true,
    ]);
    ProductReference::query()->create([
        'company_id' => $user->company_id,
        'item_id' => $item->id,
        'channel' => EbayConfiguration::CHANNEL,
        'marketplace_id' => 'EBAY_MOTORS_US',
        'reference_type' => ProductReference::TYPE_EBAY_EPID,
        'external_product_id' => '1122066940',
        'target_key' => 'item:'.$item->id,
        'title' => 'BMW brake caliper',
        'facts' => ['Brand' => 'BMW'],
        'source' => ProductReference::SOURCE_IMPORTED,
        'review_status' => ProductReference::REVIEW_SUGGESTED,
        'imported_at' => Carbon::now(),
    ]);

    $draft = app(EbayListingReadinessService::class)->refreshForItem($item->fresh());

    expect($draft->readiness_status)->toBe(EbayListingReadinessService::STATUS_READY)
        ->and($draft->marketplace_id)->toBe('EBAY_MOTORS_US')
        ->and($draft->category_id)->toBe('33563')
        ->and($draft->mapped_aspects)->toBe(['Brand' => 'BMW'])
        ->and($draft->policy_ids)->toBe(['return' => 'RET-1', 'fulfillment' => 'FUL-1', 'payment' => 'PAY-1'])
        ->and($draft->merchant_location_key)->toBe('california_shop')
        ->and($draft->readiness_snapshot['blockers'])->toBe([])
        ->and($draft->readiness_snapshot['aspects'][0]['source'])->toBe('catalog_attribute')
        ->and($draft->readiness_snapshot['product_references'][0]['external_product_id'])->toBe('1122066940');
});

test('eBay listing readiness blocks invalid mapped enum values', function (): void {
    $user = createAdminUser();
    $scope = Scope::company($user->company_id);
    app(SettingsService::class)->set('marketplace.ebay.marketplace_id', 'EBAY_US', $scope);

    $template = ProductTemplate::factory()->create([
        'company_id' => $user->company_id,
        'metadata' => ['marketplace' => ['ebay' => ['marketplace_id' => 'EBAY_MOTORS_US', 'category_tree_id' => '100', 'category_id' => '33563']]],
    ]);
    $item = Item::factory()->create([
        'company_id' => $user->company_id,
        'product_template_id' => $template->id,
        'target_price_amount' => 10000,
    ]);
    $finish = CatalogAttribute::factory()->create([
        'company_id' => $user->company_id,
        'code' => 'finish',
        'name' => 'Finish',
    ]);
    AttributeValue::factory()->create([
        'item_id' => $item->id,
        'attribute_id' => $finish->id,
        'display_value' => 'Invisible',
        'value' => ['text' => 'Invisible'],
    ]);
    AspectMapping::query()->create([
        'company_id' => $user->company_id,
        'catalog_attribute_id' => $finish->id,
        'channel' => EbayConfiguration::CHANNEL,
        'marketplace_id' => 'EBAY_MOTORS_US',
        'category_tree_id' => '100',
        'category_id' => '33563',
        'internal_attribute_code' => 'finish',
        'ebay_aspect_name' => 'Finish',
        'value_normalization' => AspectMapping::NORMALIZATION_COPY,
        'enum_values' => ['Powder-Coated', 'Painted'],
        'requirement_status' => AspectMapping::REQUIREMENT_OPTIONAL,
        'mapping_confidence' => AspectMapping::CONFIDENCE_MANUAL,
        'is_enabled' => true,
    ]);

    $draft = app(EbayListingReadinessService::class)->refreshForItem($item->fresh());

    expect($draft->readiness_status)->toBe(EbayListingReadinessService::STATUS_BLOCKED)
        ->and(collect($draft->readiness_snapshot['blockers'])->pluck('key')->all())->toContain('aspect_invalid_Finish');
});

test('eBay listing payload builder prepares inventory offer compatibility and publish operations', function (): void {
    $user = createAdminUser();
    $item = Item::factory()->create([
        'company_id' => $user->company_id,
        'sku' => 'BMW-CALIPER-1',
        'title' => 'BMW rear brake caliper pair',
        'quantity_on_hand' => 2,
        'target_price_amount' => 25000,
        'currency_code' => 'USD',
    ]);
    CatalogDescription::factory()->create([
        'item_id' => $item->id,
        'body' => 'Used BMW rear brake caliper pair.',
        'is_accepted' => true,
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
    ItemFitment::query()->create([
        'company_id' => $user->company_id,
        'item_id' => $item->id,
        'compatibility_properties' => ['Year' => '2011', 'Make' => 'BMW', 'Model' => '135i'],
        'source' => ItemFitment::SOURCE_OPERATOR,
        'confidence' => ItemFitment::CONFIDENCE_SELLER_CONFIRMED,
    ]);
    $draft = ListingDraft::query()->create([
        'company_id' => $user->company_id,
        'item_id' => $item->id,
        'channel' => EbayConfiguration::CHANNEL,
        'marketplace_id' => 'EBAY_MOTORS_US',
        'external_sku' => 'BMW-CALIPER-1',
        'title' => 'BMW rear brake caliper pair',
        'category_id' => '33563',
        'status' => 'draft',
        'management_state' => 'local',
        'mapped_aspects' => ['Brand' => 'BMW', 'Condition' => 'Used'],
        'policy_ids' => ['return' => 'RET-1', 'fulfillment' => 'FUL-1', 'payment' => 'PAY-1'],
        'merchant_location_key' => 'california_shop',
        'readiness_status' => EbayListingReadinessService::STATUS_READY,
    ]);

    $payload = app(EbayListingPayloadBuilder::class)->build($draft);

    expect($payload['inventory_item']['product']['imageUrls'])->toBe(['https://cdn.example.test/caliper.jpg'])
        ->and($payload['inventory_item']['availability']['shipToLocationAvailability']['quantity'])->toBe(2)
        ->and($payload['inventory_item']['condition'])->toBe('Used')
        ->and($payload['compatibility']['applications'][0]['properties'])->toBe(['Year' => '2011', 'Make' => 'BMW', 'Model' => '135i'])
        ->and($payload['offer']['pricingSummary']['price'])->toBe(['value' => '250.00', 'currency' => 'USD'])
        ->and($payload['operations'])->toBe(['inventory_item_upsert', 'compatibility_upsert', 'offer_create_or_update', 'offer_publish']);
});
