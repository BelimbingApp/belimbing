<?php

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Modules\Commerce\Catalog\Models\Description;
use App\Modules\Commerce\Inventory\Models\ItemFitment;
use App\Modules\Commerce\Marketplace\Models\ListingDraft;

class EbayListingPayloadBuilder
{
    /**
     * Build the local publish contract for eBay Inventory API operations.
     *
     * The result intentionally mirrors the planned operation sequence without
     * performing network calls: inventory item upsert, compatibility upsert,
     * offer create/update, then publish. Live eBay mutation code should consume
     * this rather than rebuilding item facts from unrelated UI state.
     *
     * @return array<string, mixed>
     */
    public function build(ListingDraft $draft): array
    {
        $draft->loadMissing([
            'item.photos.mediaAsset',
            'item.fitments',
            'item.descriptions',
        ]);

        $item = $draft->item;

        if ($item === null) {
            return [];
        }

        $description = $item->descriptions->firstWhere('is_accepted', true);
        $photoUrls = $this->publicPhotoUrls($draft);

        return [
            'marketplace_id' => $draft->marketplace_id,
            'sku' => $draft->external_sku ?? $item->sku,
            'inventory_item' => [
                'sku' => $draft->external_sku ?? $item->sku,
                'product' => array_filter([
                    'title' => $draft->title ?? $item->title,
                    'description' => $description instanceof Description ? $description->body : null,
                    'aspects' => $draft->mapped_aspects ?? [],
                    'imageUrls' => $photoUrls,
                ], fn (mixed $value): bool => $value !== null && $value !== []),
                'condition' => $this->conditionValue($draft),
                'availability' => [
                    'shipToLocationAvailability' => [
                        'quantity' => max(0, $item->quantity_on_hand),
                    ],
                ],
            ],
            'compatibility' => [
                'universal' => $item->fitments->contains(fn (ItemFitment $fitment): bool => $fitment->is_universal),
                'applications' => $item->fitments
                    ->reject(fn (ItemFitment $fitment): bool => $fitment->is_universal)
                    ->map(fn (ItemFitment $fitment): array => [
                        'properties' => $fitment->compatibility_properties ?? [],
                        'source' => $fitment->source,
                        'confidence' => $fitment->confidence,
                    ])
                    ->values()
                    ->all(),
            ],
            'offer' => [
                'marketplaceId' => $draft->marketplace_id,
                'format' => 'FIXED_PRICE',
                'availableQuantity' => max(0, $item->quantity_on_hand),
                'categoryId' => $draft->category_id,
                'listingDescription' => $description instanceof Description ? $description->body : null,
                'pricingSummary' => [
                    'price' => [
                        'value' => number_format(((int) $item->target_price_amount) / 100, 2, '.', ''),
                        'currency' => $item->currency_code,
                    ],
                ],
                'listingPolicies' => array_filter([
                    'fulfillmentPolicyId' => $draft->policy_ids['fulfillment'] ?? null,
                    'paymentPolicyId' => $draft->policy_ids['payment'] ?? null,
                    'returnPolicyId' => $draft->policy_ids['return'] ?? null,
                ]),
                'merchantLocationKey' => $draft->merchant_location_key,
            ],
            'operations' => [
                'inventory_item_upsert',
                'compatibility_upsert',
                'offer_create_or_update',
                'offer_publish',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function publicPhotoUrls(ListingDraft $draft): array
    {
        return $draft->item?->photos
            ->map(fn ($photo): mixed => $photo->mediaAsset?->metadata['public_url'] ?? null)
            ->filter(fn (mixed $url): bool => is_string($url) && str_starts_with($url, 'https://'))
            ->values()
            ->all() ?? [];
    }

    private function conditionValue(ListingDraft $draft): ?string
    {
        $aspects = $draft->mapped_aspects ?? [];

        return collect(['Condition', 'Condition Grade'])
            ->map(fn (string $key): mixed => $aspects[$key] ?? null)
            ->first(fn (mixed $value): bool => is_string($value) && trim($value) !== '');
    }
}
