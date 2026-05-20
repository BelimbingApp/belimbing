<?php

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Catalog\Models\AttributeValue;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Models\AccountResource;
use App\Modules\Commerce\Marketplace\Models\AspectMapping;
use App\Modules\Commerce\Marketplace\Models\ListingDraft;
use App\Modules\Commerce\Marketplace\Models\MarketplaceMetadata;
use App\Modules\Commerce\Marketplace\Models\ProductReference;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class EbayListingReadinessService
{
    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly EbayConfiguration $configuration,
        private readonly SettingsService $settings,
        private readonly EbayOAuthService $oauth,
    ) {}

    public function refreshForItem(Item $item): ListingDraft
    {
        $item->loadMissing([
            'productTemplate',
            'photos.mediaAsset',
            'fitments',
            'catalogAttributeValues.attribute',
            'descriptions',
        ]);

        $companyId = $item->company_id;
        $config = $this->configuration->forCompany($companyId);
        $sellerMarketplaceId = (string) $config['marketplace_id'];
        $templateMapping = $this->templateMapping($item);
        $marketplaceId = $templateMapping['marketplace_id'] ?? $sellerMarketplaceId;
        $categoryTreeId = $templateMapping['category_tree_id'] ?? null;
        $categoryId = $templateMapping['category_id'] ?? null;
        $policyIds = $this->policyIds($companyId);
        $mappedAspects = $this->mappedAspects($item, $marketplaceId, $categoryId, $categoryTreeId);
        $productReferences = ProductReference::query()
            ->where('company_id', $companyId)
            ->where('channel', EbayConfiguration::CHANNEL)
            ->where('item_id', $item->id)
            ->get();
        $aspectFacts = $this->aspectFacts($item, $marketplaceId, $categoryId, $categoryTreeId, $productReferences->all());

        [$blockers, $warnings] = $this->gaps(
            item: $item,
            categoryId: $categoryId,
            policyIds: $policyIds,
            merchantLocationKey: $this->merchantLocationKey($companyId),
            mappedAspects: $mappedAspects,
            productReferenceCount: $productReferences->count(),
            marketplaceId: $marketplaceId,
            sellerMarketplaceId: $sellerMarketplaceId,
            categoryTreeId: $categoryTreeId,
            aspectFacts: $aspectFacts,
        );

        $readinessStatus = $blockers === [] ? self::STATUS_READY : self::STATUS_BLOCKED;

        return ListingDraft::query()->updateOrCreate(
            [
                'company_id' => $companyId,
                'item_id' => $item->id,
                'channel' => EbayConfiguration::CHANNEL,
                'marketplace_id' => $marketplaceId,
            ],
            [
                'external_sku' => $item->sku,
                'title' => $item->title,
                'category_id' => $categoryId,
                'status' => 'draft',
                'management_state' => 'local',
                'mapped_aspects' => $mappedAspects,
                'policy_ids' => $policyIds,
                'merchant_location_key' => $this->merchantLocationKey($companyId),
                'photo_asset_ids' => $item->photos->pluck('media_asset_id')->values()->all(),
                'readiness_status' => $readinessStatus,
                'readiness_snapshot' => [
                    'blockers' => $blockers,
                    'warnings' => $warnings,
                    'facts' => [
                        'category_tree_id' => $categoryTreeId,
                        'category_id' => $categoryId,
                        'fitment_count' => $item->fitments->count(),
                        'photo_count' => $item->photos->count(),
                        'mapped_aspect_count' => count($mappedAspects),
                        'product_reference_count' => $productReferences->count(),
                        'public_photo_count' => $this->publicPhotoUrls($item)->count(),
                    ],
                    'aspects' => $aspectFacts,
                    'product_references' => $productReferences->map(fn (ProductReference $reference): array => [
                        'type' => $reference->reference_type,
                        'external_product_id' => $reference->external_product_id,
                        'source' => $reference->source,
                        'review_status' => $reference->review_status,
                    ])->values()->all(),
                ],
                'metadata_checked_at' => Carbon::now(),
                'metadata_version_key' => $this->metadataVersionKey($marketplaceId, $categoryTreeId, $categoryId),
                'last_failure_summary' => null,
            ],
        );
    }

    /**
     * @return array{marketplace_id?: string, category_tree_id?: string, category_id?: string}
     */
    private function templateMapping(Item $item): array
    {
        $metadata = $item->productTemplate?->metadata ?? [];

        return collect([
            'marketplace_id' => data_get($metadata, 'marketplace.ebay.marketplace_id'),
            'category_tree_id' => data_get($metadata, 'marketplace.ebay.category_tree_id'),
            'category_id' => data_get($metadata, 'marketplace.ebay.category_id'),
        ])
            ->map(fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null)
            ->filter()
            ->all();
    }

    /**
     * @return array{return: string|null, fulfillment: string|null, payment: string|null}
     */
    private function policyIds(int $companyId): array
    {
        $scope = Scope::company($companyId);

        return [
            'return' => $this->nullableSetting('marketplace.ebay.default_return_policy_id', $scope),
            'fulfillment' => $this->nullableSetting('marketplace.ebay.default_fulfillment_policy_id', $scope),
            'payment' => $this->nullableSetting('marketplace.ebay.default_payment_policy_id', $scope),
        ];
    }

    private function merchantLocationKey(int $companyId): ?string
    {
        return $this->nullableSetting('marketplace.ebay.default_merchant_location_key', Scope::company($companyId));
    }

    private function nullableSetting(string $key, Scope $scope): ?string
    {
        $value = $this->settings->get($key, null, $scope);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function mappedAspects(Item $item, string $marketplaceId, ?string $categoryId, ?string $categoryTreeId): array
    {
        return collect($this->aspectFacts($item, $marketplaceId, $categoryId, $categoryTreeId, []))
            ->filter(fn (array $fact): bool => $fact['value'] !== null && ($fact['validation'] ?? 'ok') === 'ok')
            ->mapWithKeys(fn (array $fact): array => [$fact['name'] => $fact['normalized_value'] ?? $fact['value']])
            ->all();
    }

    /**
     * @param  list<ProductReference>  $productReferences
     * @return list<array{name: string, value: string|null, normalized_value?: string|null, source: string, confidence: string, internal_attribute_code?: string, validation?: string, message?: string}>
     */
    private function aspectFacts(Item $item, string $marketplaceId, ?string $categoryId, ?string $categoryTreeId, array $productReferences): array
    {
        if ($categoryId === null) {
            return [];
        }

        $attributeValues = $item->catalogAttributeValues->keyBy(fn (AttributeValue $value): string => (string) $value->attribute?->code);

        $mappedFacts = AspectMapping::query()
            ->forCategory($item->company_id, EbayConfiguration::CHANNEL, $marketplaceId, $categoryId, $categoryTreeId)
            ->get()
            ->map(function (AspectMapping $mapping) use ($attributeValues): array {
                $value = $attributeValues->get($mapping->internal_attribute_code)?->display_value;
                $value = is_string($value) && trim($value) !== '' ? trim($value) : null;
                $normalized = $this->normalizeAspectValue($value, $mapping->value_normalization);
                $validation = $this->validateAspectValue($normalized, $mapping->enum_values);

                return array_filter([
                    'name' => $mapping->ebay_aspect_name,
                    'value' => $value,
                    'normalized_value' => $normalized,
                    'source' => 'catalog_attribute',
                    'confidence' => $mapping->mapping_confidence,
                    'internal_attribute_code' => $mapping->internal_attribute_code,
                    'validation' => $validation === null ? 'ok' : 'invalid',
                    'message' => $validation,
                ], fn (mixed $entry): bool => $entry !== null);
            })
            ->values()
            ->all();

        $mappedNames = collect($mappedFacts)->pluck('name')->all();
        $suggestedFacts = collect($productReferences)
            ->flatMap(fn (ProductReference $reference): array => $this->suggestedAspectFacts($reference, $mappedNames))
            ->values()
            ->all();

        return [...$mappedFacts, ...$suggestedFacts];
    }

    /**
     * @param  array{return: string|null, fulfillment: string|null, payment: string|null}  $policyIds
     * @param  array<string, mixed>  $mappedAspects
     * @param  list<array{name: string, value: string|null, normalized_value?: string|null, source: string, confidence: string, internal_attribute_code?: string, validation?: string, message?: string}>  $aspectFacts
     * @return array{0: list<array{key: string, label: string}>, 1: list<array{key: string, label: string}>}
     */
    private function gaps(
        Item $item,
        ?string $categoryId,
        array $policyIds,
        ?string $merchantLocationKey,
        array $mappedAspects,
        int $productReferenceCount,
        string $marketplaceId,
        string $sellerMarketplaceId,
        ?string $categoryTreeId,
        array $aspectFacts,
    ): array {
        $blockers = [];
        $warnings = [];

        if ($categoryId === null) {
            $blockers[] = $this->gap('category', __('Map this item template to an eBay category.'), 'settings');
        }

        if ($item->target_price_amount === null || $item->target_price_amount <= 0) {
            $blockers[] = $this->gap('price', __('Set a target price.'), 'item_facts');
        }

        if ($item->quantity_on_hand <= 0) {
            $blockers[] = $this->gap('quantity', __('Set quantity on hand above zero.'), 'item_facts');
        }

        if ($item->fitments->isEmpty()) {
            $blockers[] = $this->gap('fitment', __('Add fitment entries or explicitly mark universal fit.'), 'fitment');
        }

        if ($item->photos->isEmpty()) {
            $blockers[] = $this->gap('photos', __('Add at least one photo.'), 'photos');
        } elseif (! $this->hasPublishSafePhotos($item)) {
            $blockers[] = $this->gap('publish_safe_photos', __('Use public HTTPS photo URLs before publishing to eBay.'), 'photos');
        }

        if (! $item->descriptions->contains('is_accepted', true)) {
            $warnings[] = $this->gap('description', __('Accept a listing description before publishing.'), 'descriptions');
        }

        if ($item->photos->count() > 0 && $item->photos->count() < 3) {
            $warnings[] = $this->gap('photo_coverage', __('Add more photos when possible: multiple angles, labels, connectors, mounts, and visible defects.'), 'photos');
        }

        foreach ($policyIds as $kind => $policyId) {
            if ($policyId === null) {
                $blockers[] = $this->gap('policy_'.$kind, __('Choose a default :kind policy in eBay settings.', ['kind' => $kind]), 'settings');
            } elseif (! $this->hasEnabledAccountResource($item->company_id, [$sellerMarketplaceId, $marketplaceId], 'policy', $policyId)) {
                $warnings[] = $this->gap('policy_'.$kind.'_unverified', __('Re-import eBay setup choices to confirm the saved :kind policy is still enabled.', ['kind' => $kind]), 'settings');
            }
        }

        if ($merchantLocationKey === null) {
            $blockers[] = $this->gap('merchant_location', __('Choose a default merchant location in eBay settings.'), 'settings');
        } elseif (! $this->hasEnabledAccountResource($item->company_id, [$sellerMarketplaceId, $marketplaceId], AccountResource::KIND_INVENTORY_LOCATION, $merchantLocationKey)) {
            $warnings[] = $this->gap('merchant_location_unverified', __('Re-import eBay setup choices to confirm the saved merchant location is still enabled.'), 'settings');
        }

        foreach ($this->requiredAspectNames($marketplaceId, $categoryTreeId, $categoryId) as $aspectName) {
            if (! array_key_exists($aspectName, $mappedAspects)) {
                $blockers[] = $this->gap('aspect_'.$aspectName, __('Map or enter required eBay aspect: :aspect.', ['aspect' => $aspectName]), 'attributes');
            }
        }

        foreach ($aspectFacts as $fact) {
            if (($fact['validation'] ?? 'ok') === 'invalid') {
                $blockers[] = $this->gap('aspect_invalid_'.$fact['name'], (string) ($fact['message'] ?? __('Fix an invalid eBay aspect value.')), 'attributes');
            }
        }

        if ($this->categoryHasConditionPolicy($marketplaceId, $categoryId) && ! $this->hasAnyAspect($aspectFacts, ['Condition', 'Condition Grade', 'Type'])) {
            $blockers[] = $this->gap('condition_mapping', __('Map a Belimbing condition attribute before publishing to this eBay category.'), 'attributes');
        }

        if (! $this->hasAnyAspect($aspectFacts, ['Brand'])) {
            $warnings[] = $this->gap('identifier_brand', __('Add a brand identifier when it is known.'), 'attributes');
        }

        if (! $this->hasAnyAspect($aspectFacts, ['Manufacturer Part Number', 'MPN', 'OE/OEM Part Number', 'Interchange Part Number'])) {
            $warnings[] = $this->gap('identifier_part_number', __('Add manufacturer, OEM, or interchange part numbers when available.'), 'attributes');
        }

        if (! $this->titleIncludesUsefulSpecific($item->title, $mappedAspects)) {
            $warnings[] = $this->gap('title_guidance', __('Improve the title with useful specifics such as part type, brand, part number, side, or placement.'), 'item_facts');
        }

        if (($policyIds['fulfillment'] ?? null) !== null) {
            $warnings[] = $this->gap('package_shipping_facts', __('Confirm package weight and dimensions before publishing if the selected shipping policy requires them.'), 'item_facts');
        }

        if (! $this->hasFreshCategoryMetadata($marketplaceId, $categoryTreeId, $categoryId)) {
            $warnings[] = $this->gap('metadata_stale', __('Refresh eBay metadata before publishing so category rules are current.'), 'settings');
        }

        if ($this->oauth->tokenForCompany($item->company_id)?->refresh_token === null) {
            $blockers[] = $this->gap('oauth_connection', __('Connect eBay before publishing.'), 'settings');
        }

        if ($productReferenceCount === 0) {
            $warnings[] = $this->gap('product_reference', __('No eBay catalog/ePID suggestion has been imported for this item.'), 'settings');
        }

        return [$blockers, $warnings];
    }

    /**
     * @return array{key: string, label: string, action: string}
     */
    private function gap(string $key, string $label, string $action): array
    {
        return compact('key', 'label', 'action');
    }

    /**
     * @return list<string>
     */
    private function requiredAspectNames(string $marketplaceId, ?string $categoryTreeId, ?string $categoryId): array
    {
        if ($categoryTreeId === null || $categoryId === null) {
            return [];
        }

        $metadata = MarketplaceMetadata::query()
            ->where('channel', EbayConfiguration::CHANNEL)
            ->where('marketplace_id', $marketplaceId)
            ->where('kind', EbayMetadataService::KIND_CATEGORY_ASPECTS)
            ->where('key', $categoryTreeId.':'.$categoryId)
            ->latest('fetched_at')
            ->first();

        return collect($metadata?->payload['aspects'] ?? [])
            ->filter(fn (mixed $aspect): bool => (bool) data_get($aspect, 'aspectConstraint.aspectRequired'))
            ->map(fn (mixed $aspect): string => (string) data_get($aspect, 'localizedAspectName'))
            ->filter()
            ->values()
            ->all();
    }

    private function metadataVersionKey(string $marketplaceId, ?string $categoryTreeId, ?string $categoryId): ?string
    {
        if ($categoryTreeId === null || $categoryId === null) {
            return null;
        }

        $metadata = MarketplaceMetadata::query()
            ->where('channel', EbayConfiguration::CHANNEL)
            ->where('marketplace_id', $marketplaceId)
            ->where('key', $categoryTreeId.':'.$categoryId)
            ->latest('fetched_at')
            ->first();

        return $metadata?->fetched_at?->toISOString();
    }

    private function hasFreshCategoryMetadata(string $marketplaceId, ?string $categoryTreeId, ?string $categoryId): bool
    {
        if ($categoryTreeId === null || $categoryId === null) {
            return false;
        }

        $metadata = MarketplaceMetadata::query()
            ->where('channel', EbayConfiguration::CHANNEL)
            ->where('marketplace_id', $marketplaceId)
            ->where('kind', EbayMetadataService::KIND_CATEGORY_ASPECTS)
            ->where('key', $categoryTreeId.':'.$categoryId)
            ->latest('fetched_at')
            ->first();

        return $metadata?->isFresh() ?? false;
    }

    /**
     * @param  list<string>  $marketplaceIds
     */
    private function hasEnabledAccountResource(int $companyId, array $marketplaceIds, string $kind, string $externalId): bool
    {
        $marketplaceIds = array_values(array_unique($marketplaceIds));

        if ($kind === 'policy') {
            return AccountResource::query()
                ->where('company_id', $companyId)
                ->where('channel', EbayConfiguration::CHANNEL)
                ->whereIn('marketplace_id', $marketplaceIds)
                ->whereIn('kind', [AccountResource::KIND_PAYMENT_POLICY, AccountResource::KIND_RETURN_POLICY, AccountResource::KIND_FULFILLMENT_POLICY])
                ->where('external_id', $externalId)
                ->get()
                ->contains(fn (AccountResource $resource): bool => $resource->isEnabled());
        }

        $resource = AccountResource::query()
            ->where('company_id', $companyId)
            ->where('channel', EbayConfiguration::CHANNEL)
            ->whereIn('marketplace_id', $marketplaceIds)
            ->where('kind', $kind)
            ->where('external_id', $externalId)
            ->first();

        return $resource?->isEnabled() ?? false;
    }

    /**
     * @param  list<array{name: string, value: string|null, normalized_value?: string|null, source: string, confidence: string, internal_attribute_code?: string, validation?: string, message?: string}>  $aspectFacts
     * @param  list<string>  $names
     */
    private function hasAnyAspect(array $aspectFacts, array $names): bool
    {
        return collect($aspectFacts)->contains(fn (array $fact): bool => $fact['value'] !== null && in_array($fact['name'], $names, true));
    }

    private function hasPublishSafePhotos(Item $item): bool
    {
        return $this->publicPhotoUrls($item)->isNotEmpty();
    }

    private function publicPhotoUrls(Item $item): Collection
    {
        return $item->photos
            ->map(fn ($photo): mixed => $photo->mediaAsset?->metadata['public_url'] ?? null)
            ->filter(fn (mixed $url): bool => is_string($url) && str_starts_with($url, 'https://'))
            ->values();
    }

    private function normalizeAspectValue(?string $value, string $normalization): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($normalization) {
            AspectMapping::NORMALIZATION_NUMBER => is_numeric($value) ? (string) (float) $value : $value,
            AspectMapping::NORMALIZATION_BOOLEAN => in_array(strtolower($value), ['1', 'true', 'yes', 'y'], true) ? 'Yes' : (in_array(strtolower($value), ['0', 'false', 'no', 'n'], true) ? 'No' : $value),
            default => trim($value),
        };
    }

    /**
     * @param  array<int, string>|null  $enumValues
     */
    private function validateAspectValue(?string $value, ?array $enumValues): ?string
    {
        if ($value === null || $enumValues === null || $enumValues === []) {
            return null;
        }

        if (in_array($value, $enumValues, true)) {
            return null;
        }

        return __('The eBay aspect value ":value" is not allowed for the selected category.', ['value' => $value]);
    }

    /**
     * @param  list<string>  $alreadyMappedNames
     * @return list<array{name: string, value: string|null, source: string, confidence: string}>
     */
    private function suggestedAspectFacts(ProductReference $reference, array $alreadyMappedNames): array
    {
        $aspects = $reference->facts['aspects'] ?? [];

        if (! is_array($aspects)) {
            return [];
        }

        return collect($aspects)
            ->map(function (mixed $value, mixed $name) use ($alreadyMappedNames): ?array {
                if (! is_string($name) || in_array($name, $alreadyMappedNames, true)) {
                    return null;
                }

                $value = is_array($value) ? reset($value) : $value;

                return is_scalar($value) && trim((string) $value) !== '' ? [
                    'name' => $name,
                    'value' => trim((string) $value),
                    'source' => 'ebay_product_reference',
                    'confidence' => AspectMapping::CONFIDENCE_SUGGESTED,
                ] : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function categoryHasConditionPolicy(string $marketplaceId, ?string $categoryId): bool
    {
        if ($categoryId === null) {
            return false;
        }

        $metadata = MarketplaceMetadata::query()
            ->where('channel', EbayConfiguration::CHANNEL)
            ->where('marketplace_id', $marketplaceId)
            ->where('kind', EbayMetadataService::KIND_ITEM_CONDITION_POLICIES)
            ->where(function ($query) use ($categoryId): void {
                $query->where('key', $categoryId)
                    ->orWhere('key', 'all');
            })
            ->latest('fetched_at')
            ->first();

        return collect($metadata?->payload['itemConditionPolicies'] ?? [])
            ->contains(fn (mixed $policy): bool => in_array($categoryId, (array) data_get($policy, 'categoryIds', []), true)
                || data_get($policy, 'categoryId') === $categoryId);
    }

    /**
     * @param  array<string, mixed>  $mappedAspects
     */
    private function titleIncludesUsefulSpecific(string $title, array $mappedAspects): bool
    {
        $title = strtolower($title);

        return collect($mappedAspects)
            ->filter(fn (mixed $value, string $name): bool => in_array($name, ['Brand', 'Manufacturer Part Number', 'MPN', 'OE/OEM Part Number', 'Interchange Part Number', 'Placement on Vehicle'], true)
                && is_string($value)
                && trim($value) !== '')
            ->contains(fn (string $value): bool => str_contains($title, strtolower($value)));
    }
}
