<?php

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Modules\Commerce\Catalog\Models\Description;
use App\Modules\Commerce\Inventory\Models\ItemFitment;
use App\Modules\Commerce\Marketplace\Models\ListingDraft;
use App\Modules\Commerce\Marketplace\Models\ProductReference;

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
            'item.catalogAttributeValues.attribute',
        ]);

        $item = $draft->item;

        if ($item === null) {
            return [];
        }

        $description = $item->descriptions->firstWhere('is_accepted', true);
        $photoUrls = $this->publicPhotoUrls($draft);
        $productReference = ProductReference::query()
            ->where('company_id', $draft->company_id)
            ->where('item_id', $item->id)
            ->where('channel', EbayConfiguration::CHANNEL)
            ->where('review_status', ProductReference::REVIEW_ACCEPTED)
            ->latest('imported_at')
            ->first();
        $productAspects = $this->productAspects($draft->mapped_aspects ?? []);
        $productIdentifiers = $this->productIdentifiers($draft->mapped_aspects ?? [], $productReference);

        return [
            'marketplace_id' => $draft->marketplace_id,
            'metadata_marketplace_id' => $draft->metadata_marketplace_id,
            'sku' => $draft->external_sku ?? $item->sku,
            'inventory_item' => [
                'product' => array_filter([
                    'title' => $draft->title ?? $item->title,
                    'description' => $description instanceof Description ? $description->body : null,
                    'aspects' => $productAspects,
                    'imageUrls' => $photoUrls,
                    ...$productIdentifiers,
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
                        'compatibilityProperties' => collect($fitment->compatibility_properties ?? [])
                            ->map(fn (mixed $value, mixed $name): ?array => is_string($name) && is_scalar($value) && trim((string) $value) !== ''
                                ? ['name' => strtolower(trim($name)), 'value' => trim((string) $value)]
                                : null)
                            ->filter()
                            ->values()
                            ->all(),
                        'notes' => $fitment->notes,
                    ])
                    ->filter(fn (array $application): bool => $application['compatibilityProperties'] !== [])
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

    /**
     * @param  array<string, mixed>  $mappedAspects
     * @return array<string, array<int, string>>
     */
    private function productAspects(array $mappedAspects): array
    {
        return collect($mappedAspects)
            ->mapWithKeys(function (mixed $value, mixed $name): array {
                if (! is_string($name) || trim($name) === '') {
                    return [];
                }

                $values = is_array($value) ? $value : [$value];
                $values = collect($values)
                    ->map(fn (mixed $entry): ?string => is_scalar($entry) && trim((string) $entry) !== '' ? trim((string) $entry) : null)
                    ->filter()
                    ->values()
                    ->all();

                return $values === [] ? [] : [trim($name) => $values];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $mappedAspects
     * @return array<string, string>
     */
    private function productIdentifiers(array $mappedAspects, ?ProductReference $reference): array
    {
        $identifiers = [];
        $brand = $this->firstAspectValue($mappedAspects, ['Brand']);
        $mpn = $this->firstAspectValue($mappedAspects, ['Manufacturer Part Number', 'MPN']);

        if ($brand !== null) {
            $identifiers['brand'] = $brand;
        }

        if ($mpn !== null) {
            $identifiers['mpn'] = $mpn;
        }

        if ($reference instanceof ProductReference && $reference->reference_type === ProductReference::TYPE_EBAY_EPID) {
            $identifiers['epid'] = $reference->external_product_id;
        }

        return $identifiers;
    }

    /**
     * @param  array<string, mixed>  $mappedAspects
     * @param  list<string>  $keys
     */
    private function firstAspectValue(array $mappedAspects, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $mappedAspects[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (is_array($value)) {
                $string = collect($value)
                    ->map(fn (mixed $entry): ?string => is_scalar($entry) && trim((string) $entry) !== '' ? trim((string) $entry) : null)
                    ->first(fn (?string $entry): bool => $entry !== null);

                if ($string !== null) {
                    return $string;
                }
            }
        }

        return null;
    }
}
