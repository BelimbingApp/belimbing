<?php

use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Marketplace\Models\ListingDraft;
use Illuminate\Support\Carbon;

test('listing drafts store readiness state separately from synced marketplace listings', function (): void {
    $user = createAdminUser();
    $item = Item::factory()->create(['company_id' => $user->company_id, 'sku' => 'BMW-CALIPER-PAIR']);
    $listing = Listing::factory()->create([
        'company_id' => $user->company_id,
        'item_id' => $item->id,
        'channel' => 'ebay',
        'marketplace_id' => 'EBAY_US',
        'external_sku' => 'BMW-CALIPER-PAIR',
    ]);

    $draft = ListingDraft::query()->create([
        'company_id' => $user->company_id,
        'item_id' => $item->id,
        'listing_id' => $listing->id,
        'channel' => 'ebay',
        'marketplace_id' => 'EBAY_US',
        'metadata_marketplace_id' => 'EBAY_MOTORS_US',
        'external_sku' => 'BMW-CALIPER-PAIR',
        'title' => 'BMW 135i Brembo rear brake caliper pair',
        'category_id' => '33563',
        'aspect_values' => [
            'Brand' => ['value' => 'BMW', 'source' => 'seller'],
            'Manufacturer Part Number' => ['value' => '34206785237', 'source' => 'catalog'],
        ],
        'mapped_aspects' => ['Brand' => 'BMW'],
        'policy_ids' => ['return' => 'RET-1', 'fulfillment' => 'FUL-1', 'payment' => 'PAY-1'],
        'merchant_location_key' => 'california_shop',
        'photo_asset_ids' => [10, 11],
        'readiness_status' => 'attention',
        'readiness_snapshot' => ['missing' => ['fitment']],
        'metadata_checked_at' => Carbon::parse('2026-05-20 12:00:00'),
        'metadata_version_key' => 'EBAY_MOTORS_US:100:33563',
        'publish_intent' => 'revise',
    ]);

    $draft->refresh();

    expect($draft->item->is($item))->toBeTrue()
        ->and($draft->listing->is($listing))->toBeTrue()
        ->and($draft->aspect_values['Brand']['source'])->toBe('seller')
        ->and($draft->policy_ids['return'])->toBe('RET-1')
        ->and($draft->photo_asset_ids)->toBe([10, 11])
        ->and($draft->readiness_snapshot['missing'])->toBe(['fitment'])
        ->and($draft->metadata_marketplace_id)->toBe('EBAY_MOTORS_US')
        ->and($draft->metadata_checked_at?->toDateTimeString())->toBe('2026-05-20 12:00:00');
});
