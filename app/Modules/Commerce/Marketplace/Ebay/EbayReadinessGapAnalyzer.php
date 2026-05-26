<?php

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Models\AccountResource;
use App\Modules\Commerce\Marketplace\Models\MarketplaceMetadata;
use Illuminate\Support\Collection;

class EbayReadinessGapAnalyzer
{
    private const PART_NUMBER_ASPECT_NAMES = [
        'Manufacturer Part Number',
        'MPN',
        'OE/OEM Part Number',
        'Interchange Part Number',
    ];

    private const USEFUL_TITLE_ASPECT_NAMES = [
        'Brand',
        'Manufacturer Part Number',
        'MPN',
        'OE/OEM Part Number',
        'Interchange Part Number',
        'Placement on Vehicle',
    ];

    public function __construct(
        private readonly EbayOAuthService $oauth,
    ) {}

    /**
     * @param  array{
     *     category_id: string|null,
     *     policy_ids: array{return: string|null, fulfillment: string|null, payment: string|null},
     *     merchant_location_key: string|null,
     *     mapped_aspects: array<string, mixed>,
     *     product_reference_count: int,
     *     metadata_marketplace_id: string,
     *     seller_marketplace_id: string,
     *     category_tree_id: string|null,
     *     aspect_facts: list<array{name: string, value: string|null, normalized_value?: string|null, source: string, confidence: string, internal_attribute_code?: string, validation?: string, message?: string}>,
     *     identifier_alignment: list<array{key: string, label: string, status: string, sources: array<string, list<string>>}>
     * }  $context
     * @return array{0: list<array{key: string, label: string}>, 1: list<array{key: string, label: string}>}
     */
    public function analyze(Item $item, array $context): array
    {
        $blockers = [];
        $warnings = [];

        $this->addItemReadinessGaps($item, $context['category_id'], $blockers, $warnings);
        $this->addPolicyReadinessGaps(
            $item,
            $context['policy_ids'],
            $context['merchant_location_key'],
            $context['metadata_marketplace_id'],
            $context['seller_marketplace_id'],
            $blockers,
            $warnings,
        );
        $this->addAspectReadinessGaps($item, $context, $blockers, $warnings);
        $this->addConnectionReadinessGaps($item, $context['product_reference_count'], $blockers, $warnings);

        return [$blockers, $warnings];
    }

    /**
     * @param  list<array{key: string, label: string}>  $blockers
     * @param  list<array{key: string, label: string}>  $warnings
     */
    private function addItemReadinessGaps(Item $item, ?string $categoryId, array &$blockers, array &$warnings): void
    {
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
    }

    /**
     * @param  array{return: string|null, fulfillment: string|null, payment: string|null}  $policyIds
     * @param  list<array{key: string, label: string}>  $blockers
     * @param  list<array{key: string, label: string}>  $warnings
     */
    private function addPolicyReadinessGaps(
        Item $item,
        array $policyIds,
        ?string $merchantLocationKey,
        string $metadataMarketplaceId,
        string $sellerMarketplaceId,
        array &$blockers,
        array &$warnings,
    ): void {
        foreach ($policyIds as $kind => $policyId) {
            if ($policyId === null) {
                $blockers[] = $this->gap('policy_'.$kind, __('Choose a default :kind policy in eBay settings.', ['kind' => $kind]), 'settings');
            } elseif (! $this->hasEnabledAccountResource($item->company_id, [$sellerMarketplaceId, $metadataMarketplaceId], 'policy', $policyId)) {
                $warnings[] = $this->gap('policy_'.$kind.'_unverified', __('Re-import eBay setup choices to confirm the saved :kind policy is still enabled.', ['kind' => $kind]), 'settings');
            }
        }

        if ($merchantLocationKey === null) {
            $blockers[] = $this->gap('merchant_location', __('Choose a default merchant location in eBay settings.'), 'settings');
        } elseif (! $this->hasEnabledAccountResource($item->company_id, [$sellerMarketplaceId, $metadataMarketplaceId], AccountResource::KIND_INVENTORY_LOCATION, $merchantLocationKey)) {
            $warnings[] = $this->gap('merchant_location_unverified', __('Re-import eBay setup choices to confirm the saved merchant location is still enabled.'), 'settings');
        }

        if (($policyIds['fulfillment'] ?? null) !== null) {
            $warnings[] = $this->gap('package_shipping_facts', __('Confirm package weight and dimensions before publishing if the selected shipping policy requires them.'), 'item_facts');
        }
    }

    /**
     * @param  array{
     *     category_id: string|null,
     *     mapped_aspects: array<string, mixed>,
     *     metadata_marketplace_id: string,
     *     category_tree_id: string|null,
     *     aspect_facts: list<array{name: string, value: string|null, normalized_value?: string|null, source: string, confidence: string, internal_attribute_code?: string, validation?: string, message?: string}>,
     *     identifier_alignment: list<array{key: string, label: string, status: string, sources: array<string, list<string>>}>
     * }  $context
     * @param  list<array{key: string, label: string}>  $blockers
     * @param  list<array{key: string, label: string}>  $warnings
     */
    private function addAspectReadinessGaps(Item $item, array $context, array &$blockers, array &$warnings): void
    {
        foreach ($this->requiredAspectNames($context['metadata_marketplace_id'], $context['category_tree_id'], $context['category_id']) as $aspectName) {
            if (! array_key_exists($aspectName, $context['mapped_aspects'])) {
                $blockers[] = $this->gap('aspect_'.$aspectName, __('Map or enter required eBay aspect: :aspect.', ['aspect' => $aspectName]), 'attributes');
            }
        }

        foreach ($context['aspect_facts'] as $fact) {
            if (($fact['validation'] ?? 'ok') === 'invalid') {
                $blockers[] = $this->gap('aspect_invalid_'.$fact['name'], (string) ($fact['message'] ?? __('Fix an invalid eBay aspect value.')), 'attributes');
            }
        }

        if ($this->categoryHasConditionPolicy($context['metadata_marketplace_id'], $context['category_id']) && ! $this->hasAnyAspect($context['aspect_facts'], ['Condition', 'Condition Grade', 'Type'])) {
            $blockers[] = $this->gap('condition_mapping', __('Map a Belimbing condition attribute before publishing to this eBay category.'), 'attributes');
        }

        if (! $this->hasAnyAspect($context['aspect_facts'], ['Brand'])) {
            $warnings[] = $this->gap('identifier_brand', __('Add a brand identifier when it is known.'), 'attributes');
        }

        if (! $this->hasAnyAspect($context['aspect_facts'], self::PART_NUMBER_ASPECT_NAMES)) {
            $warnings[] = $this->gap('identifier_part_number', __('Add manufacturer, OEM, or interchange part numbers when available.'), 'attributes');
        }

        if ($this->hasIdentifierConflict($context['identifier_alignment'])) {
            $warnings[] = $this->gap('identifier_conflict', __('Review imported eBay identifiers that conflict with Belimbing facts before revising.'), 'attributes');
        }

        if (! $this->titleIncludesUsefulSpecific($item->title, $context['mapped_aspects'])) {
            $warnings[] = $this->gap('title_guidance', __('Improve the title with useful specifics such as part type, brand, part number, side, or placement.'), 'item_facts');
        }

        if (! $this->hasFreshCategoryMetadata($context['metadata_marketplace_id'], $context['category_tree_id'], $context['category_id'])) {
            $warnings[] = $this->gap('metadata_stale', __('Refresh eBay metadata before publishing so category rules are current.'), 'settings');
        }
    }

    /**
     * @param  list<array{key: string, label: string}>  $blockers
     * @param  list<array{key: string, label: string}>  $warnings
     */
    private function addConnectionReadinessGaps(Item $item, int $productReferenceCount, array &$blockers, array &$warnings): void
    {
        if ($this->oauth->tokenForCompany($item->company_id)?->refresh_token === null) {
            $blockers[] = $this->gap('oauth_connection', __('Connect eBay before publishing.'), 'settings');
        }

        if ($productReferenceCount === 0) {
            $warnings[] = $this->gap('product_reference', __('No eBay catalog/ePID suggestion has been imported for this item.'), 'settings');
        }
    }

    /**
     * @return array{key: string, label: string, action: string}
     */
    private function gap(string $key, string $label, string $action): array
    {
        return compact('key', 'label', 'action');
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

    /**
     * @param  list<array{key: string, label: string, status: string, sources: array<string, list<string>>}>  $identifierAlignment
     */
    private function hasIdentifierConflict(array $identifierAlignment): bool
    {
        return collect($identifierAlignment)->contains(fn (array $alignment): bool => $alignment['status'] === 'conflict');
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
     * @param  array<string, mixed>  $mappedAspects
     */
    private function titleIncludesUsefulSpecific(string $title, array $mappedAspects): bool
    {
        $title = strtolower($title);

        return collect($mappedAspects)
            ->filter(fn (mixed $value, string $name): bool => in_array($name, self::USEFUL_TITLE_ASPECT_NAMES, true)
                && is_string($value)
                && trim($value) !== '')
            ->contains(fn (string $value): bool => str_contains($title, strtolower($value)));
    }
}
