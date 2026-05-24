<?php

use App\Modules\Commerce\Catalog\Models\Attribute;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Models\AspectMapping;

const EBAY_MOTORS_BRAKE_CATEGORY_ID = '33563';

test('aspect mappings persist the eBay aspect contract for a catalog attribute', function (): void {
    $user = createAdminUser();
    $attribute = Attribute::factory()->create([
        'company_id' => $user->company_id,
        'code' => 'manufacturer_part_number',
        'name' => 'Manufacturer Part Number',
    ]);

    $mapping = AspectMapping::query()->create([
        'company_id' => $user->company_id,
        'catalog_attribute_id' => $attribute->id,
        'channel' => EbayConfiguration::CHANNEL,
        'marketplace_id' => 'EBAY_MOTORS_US',
        'category_tree_id' => '100',
        'category_id' => EBAY_MOTORS_BRAKE_CATEGORY_ID,
        'internal_attribute_code' => 'manufacturer_part_number',
        'ebay_aspect_name' => 'Manufacturer Part Number',
        'value_normalization' => AspectMapping::NORMALIZATION_TEXT,
        'enum_values' => ['BMW', 'Brembo'],
        'requirement_status' => AspectMapping::REQUIREMENT_REQUIRED,
        'mapping_confidence' => AspectMapping::CONFIDENCE_MANUAL,
        'notes' => 'Primary identifier for Motors search and buyer confidence.',
    ]);

    $mapping->refresh();

    expect($mapping->catalogAttribute->is($attribute))->toBeTrue()
        ->and($mapping->enum_values)->toBe(['BMW', 'Brembo'])
        ->and($mapping->is_enabled)->toBeTrue()
        ->and($mapping->requirement_status)->toBe(AspectMapping::REQUIREMENT_REQUIRED);
});

test('aspect mapping scope prefers category-specific mappings over global mappings', function (): void {
    $user = createAdminUser();

    AspectMapping::query()->create([
        'company_id' => $user->company_id,
        'channel' => EbayConfiguration::CHANNEL,
        'marketplace_id' => 'EBAY_MOTORS_US',
        'category_tree_id' => null,
        'category_id' => AspectMapping::CATEGORY_ALL,
        'internal_attribute_code' => 'brand',
        'ebay_aspect_name' => 'Brand',
        'requirement_status' => AspectMapping::REQUIREMENT_RECOMMENDED,
    ]);

    AspectMapping::query()->create([
        'company_id' => $user->company_id,
        'channel' => EbayConfiguration::CHANNEL,
        'marketplace_id' => 'EBAY_MOTORS_US',
        'category_tree_id' => '100',
        'category_id' => EBAY_MOTORS_BRAKE_CATEGORY_ID,
        'internal_attribute_code' => 'brand',
        'ebay_aspect_name' => 'Brand',
        'requirement_status' => AspectMapping::REQUIREMENT_REQUIRED,
    ]);

    AspectMapping::query()->create([
        'company_id' => $user->company_id,
        'channel' => EbayConfiguration::CHANNEL,
        'marketplace_id' => 'EBAY_MOTORS_US',
        'category_tree_id' => '100',
        'category_id' => EBAY_MOTORS_BRAKE_CATEGORY_ID,
        'internal_attribute_code' => 'disabled',
        'ebay_aspect_name' => 'Disabled',
        'is_enabled' => false,
    ]);

    $mappings = AspectMapping::query()
        ->forCategory($user->company_id, EbayConfiguration::CHANNEL, 'EBAY_MOTORS_US', EBAY_MOTORS_BRAKE_CATEGORY_ID, '100')
        ->get();

    expect($mappings)->toHaveCount(2)
        ->and($mappings->pluck('category_id')->all())->toBe([EBAY_MOTORS_BRAKE_CATEGORY_ID, AspectMapping::CATEGORY_ALL])
        ->and($mappings->pluck('requirement_status')->all())->toBe([
            AspectMapping::REQUIREMENT_REQUIRED,
            AspectMapping::REQUIREMENT_RECOMMENDED,
        ]);
});
