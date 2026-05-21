<?php

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Marketplace\Models\ListingDraft;
use App\Modules\Commerce\Marketplace\Models\ProductReference;
use Illuminate\Support\Carbon;

class EbayProductReferenceImporter
{
    public function importFromListing(Listing $listing, ?ListingDraft $draft = null): ?ProductReference
    {
        $epid = $this->epid($listing->raw_payload ?? []);

        if ($epid === null || $listing->item_id === null) {
            return null;
        }

        return ProductReference::query()->updateOrCreate(
            [
                'company_id' => $listing->company_id,
                'listing_id' => $listing->id,
                'channel' => EbayConfiguration::CHANNEL,
                'reference_type' => ProductReference::TYPE_EBAY_EPID,
                'external_product_id' => $epid,
            ],
            [
                'item_id' => $listing->item_id,
                'listing_draft_id' => $draft?->id,
                'marketplace_id' => $listing->marketplace_id,
                'title' => $listing->title,
                'facts' => $this->facts($listing->raw_payload ?? []),
                'source' => ProductReference::SOURCE_IMPORTED,
                'review_status' => ProductReference::REVIEW_SUGGESTED,
                'imported_at' => Carbon::now(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function epid(array $payload): ?string
    {
        foreach (['epid', 'ePID', 'product.epid', 'product.ePID', 'product.epid.value', 'inventory_item.product.epid', 'inventory_item.product.ePID'] as $key) {
            $value = data_get($payload, $key);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function facts(array $payload): array
    {
        return array_filter([
            'aspects' => data_get($payload, 'localizedAspects') ?? data_get($payload, 'product.aspects') ?? data_get($payload, 'inventory_item.product.aspects') ?? data_get($payload, 'aspects'),
            'brand' => data_get($payload, 'brand') ?? data_get($payload, 'product.brand') ?? data_get($payload, 'inventory_item.product.brand'),
            'mpn' => data_get($payload, 'mpn') ?? data_get($payload, 'product.mpn') ?? data_get($payload, 'inventory_item.product.mpn'),
            'source_listing_payload_keys' => array_keys($payload),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
