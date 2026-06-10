<?php

use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Ebay\EbayListingPayloadBuilder;
use App\Modules\Commerce\Marketplace\Ebay\EbayListingReadinessService;
use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Marketplace\Models\ListingDraft;

/**
 * The item is the single source of truth for the buyer-facing listing description
 * (the eBay "See full item description"). These tests lock in that the item body is
 * what publishes, and that a push never silently blanks a live body when the item
 * description has not been filled in.
 */
function makeDescriptionDraft(Item $item, Listing $listing): ListingDraft
{
    return ListingDraft::query()->create([
        'company_id' => $item->company_id,
        'item_id' => $item->id,
        'listing_id' => $listing->id,
        'channel' => EbayConfiguration::CHANNEL,
        'marketplace_id' => 'EBAY_US',
        'metadata_marketplace_id' => 'EBAY_MOTORS_US',
        'external_sku' => $item->sku,
        'title' => $item->title,
        'category_id' => '33710',
        'status' => 'draft',
        'management_state' => 'local',
        'readiness_status' => EbayListingReadinessService::STATUS_READY,
    ]);
}

test('the item description is what publishes to the marketplace', function (): void {
    $user = createAdminUser();
    $item = Item::factory()->create([
        'company_id' => $user->company_id,
        'description' => 'Locally edited body that should publish.',
        'target_price_amount' => 25000,
        'currency_code' => 'USD',
    ]);

    $listing = Listing::factory()->create([
        'company_id' => $user->company_id,
        'channel' => EbayConfiguration::CHANNEL,
        'item_id' => $item->id,
        'raw_payload' => ['inventory_item' => ['product' => ['description' => 'Stale eBay body.']]],
    ]);

    $payload = app(EbayListingPayloadBuilder::class)->build(makeDescriptionDraft($item, $listing));

    expect($payload['inventory_item']['product']['description'])->toBe('Locally edited body that should publish.')
        ->and($payload['offer']['listingDescription'])->toBe('Locally edited body that should publish.');
});

test('a push falls back to the live listing body when the item description is blank', function (): void {
    $user = createAdminUser();
    $item = Item::factory()->create([
        'company_id' => $user->company_id,
        'description' => null,
        'target_price_amount' => 25000,
        'currency_code' => 'USD',
    ]);

    $listing = Listing::factory()->create([
        'company_id' => $user->company_id,
        'channel' => EbayConfiguration::CHANNEL,
        'item_id' => $item->id,
        'raw_payload' => ['inventory_item' => ['product' => ['description' => 'Existing eBay body that must not be blanked.']]],
    ]);

    $payload = app(EbayListingPayloadBuilder::class)->build(makeDescriptionDraft($item, $listing));

    expect($payload['inventory_item']['product']['description'])->toBe('Existing eBay body that must not be blanked.')
        ->and($payload['offer']['listingDescription'])->toBe('Existing eBay body that must not be blanked.');
});
