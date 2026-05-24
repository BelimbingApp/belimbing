<?php

use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Models\ProductReference;
use Illuminate\Support\Carbon;

test('product references store eBay catalog facts as suggestions for an item', function (): void {
    $user = createAdminUser();
    $item = Item::factory()->create([
        'company_id' => $user->company_id,
        'sku' => 'BMW-CALIPER-PAIR',
    ]);

    $reference = ProductReference::query()->create([
        'company_id' => $user->company_id,
        'item_id' => $item->id,
        'channel' => EbayConfiguration::CHANNEL,
        'marketplace_id' => 'EBAY_MOTORS_US',
        'reference_type' => ProductReference::TYPE_EBAY_EPID,
        'external_product_id' => '1122066940',
        'title' => 'BMW 135i Brembo rear brake caliper pair',
        'facts' => [
            'Brand' => 'BMW',
            'Manufacturer Part Number' => ['34206785237', '34206785238'],
            'Placement on Vehicle' => 'Rear',
        ],
        'source' => ProductReference::SOURCE_IMPORTED,
        'review_status' => ProductReference::REVIEW_SUGGESTED,
        'imported_at' => Carbon::parse('2026-05-20 13:00:00'),
    ]);

    $reference->refresh();

    expect($reference->item->is($item))->toBeTrue()
        ->and($reference->target_key)->toBe('item:'.$item->id)
        ->and($reference->facts['Brand'])->toBe('BMW')
        ->and($reference->facts['Manufacturer Part Number'])->toBe(['34206785237', '34206785238'])
        ->and($reference->review_status)->toBe(ProductReference::REVIEW_SUGGESTED)
        ->and($reference->imported_at?->toDateTimeString())->toBe('2026-05-20 13:00:00');
});
