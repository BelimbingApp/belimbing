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
        $aspectFacts = $this->aspectFacts($item, $marketplaceId, $categoryId, $categoryTreeId);
        $productReferences = ProductReference::query()
            ->where('company_id', $companyId)
            ->where('channel', EbayConfiguration::CHANNEL)
            ->where('item_id', $item->id)
            ->get();

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
                    ],
                    'aspects' => $aspectFacts,
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
        return collect($this->aspectFacts($item, $marketplaceId, $categoryId, $categoryTreeId))
            ->filter(fn (array $fact): bool => $fact['value'] !== null)
            ->mapWithKeys(fn (array $fact): array => [$fact['name'] => $fact['value']])
            ->all();
    }

    /**
     * @return list<array{name: string, value: string|null, source: string, confidence: string, internal_attribute_code: string}>
     */
    private function aspectFacts(Item $item, string $marketplaceId, ?string $categoryId, ?string $categoryTreeId): array
    {
        if ($categoryId === null) {
            return [];
        }

        $attributeValues = $item->catalogAttributeValues->keyBy(fn (AttributeValue $value): string => (string) $value->attribute?->code);

        return AspectMapping::query()
            ->forCategory($item->company_id, EbayConfiguration::CHANNEL, $marketplaceId, $categoryId, $categoryTreeId)
            ->get()
            ->map(function (AspectMapping $mapping) use ($attributeValues): array {
                $value = $attributeValues->get($mapping->internal_attribute_code)?->display_value;
                $value = is_string($value) && trim($value) !== '' ? trim($value) : null;

                return [
                    'name' => $mapping->ebay_aspect_name,
                    'value' => $value,
                    'source' => 'catalog_attribute',
                    'confidence' => $mapping->mapping_confidence,
                    'internal_attribute_code' => $mapping->internal_attribute_code,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array{return: string|null, fulfillment: string|null, payment: string|null}  $policyIds
     * @param  array<string, mixed>  $mappedAspects
     * @param  list<array{name: string, value: string|null, source: string, confidence: string, internal_attribute_code: string}>  $aspectFacts
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

        if (! $this->hasAnyAspect($aspectFacts, ['Brand'])) {
            $warnings[] = $this->gap('identifier_brand', __('Add a brand identifier when it is known.'), 'attributes');
        }

        if (! $this->hasAnyAspect($aspectFacts, ['Manufacturer Part Number', 'MPN', 'OE/OEM Part Number', 'Interchange Part Number'])) {
            $warnings[] = $this->gap('identifier_part_number', __('Add manufacturer, OEM, or interchange part numbers when available.'), 'attributes');
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
     * @param  list<array{name: string, value: string|null, source: string, confidence: string, internal_attribute_code: string}>  $aspectFacts
     * @param  list<string>  $names
     */
    private function hasAnyAspect(array $aspectFacts, array $names): bool
    {
        return collect($aspectFacts)->contains(fn (array $fact): bool => $fact['value'] !== null && in_array($fact['name'], $names, true));
    }

    private function hasPublishSafePhotos(Item $item): bool
    {
        return $item->photos->contains(function ($photo): bool {
            $url = $photo->mediaAsset?->metadata['public_url'] ?? null;

            return is_string($url) && str_starts_with($url, 'https://');
        });
    }
}
