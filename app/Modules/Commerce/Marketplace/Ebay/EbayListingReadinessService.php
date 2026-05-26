<?php

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Commerce\Catalog\Models\AttributeValue;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Models\AspectMapping;
use App\Modules\Commerce\Marketplace\Models\ListingDraft;
use App\Modules\Commerce\Marketplace\Models\MarketplaceMetadata;
use App\Modules\Commerce\Marketplace\Models\ProductReference;
use App\Modules\Commerce\Plugins\Services\CommercePluginRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class EbayListingReadinessService
{
    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    private const MANUFACTURER_PART_NUMBER_ASPECT = 'Manufacturer Part Number';

    private const OE_PART_NUMBER_ASPECT = 'OE/OEM Part Number';

    private const INTERCHANGE_PART_NUMBER_ASPECT = 'Interchange Part Number';

    private const PART_NUMBER_ASPECT_NAMES = [
        self::MANUFACTURER_PART_NUMBER_ASPECT,
        'MPN',
        self::OE_PART_NUMBER_ASPECT,
        self::INTERCHANGE_PART_NUMBER_ASPECT,
    ];

    public function __construct(
        private readonly EbayConfiguration $configuration,
        private readonly SettingsService $settings,
        private readonly CommercePluginRegistry $commercePlugins,
        private readonly EbayReadinessGapAnalyzer $gapAnalyzer,
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
        $metadataMarketplaceId = $templateMapping['marketplace_id'] ?? $sellerMarketplaceId;
        $categoryTreeId = $templateMapping['category_tree_id'] ?? null;
        $categoryId = $templateMapping['category_id'] ?? null;
        $existingDraft = $this->existingDraft($companyId, $item->id, $sellerMarketplaceId);
        $policyIds = $this->policyIds($companyId);
        $mappedAspects = $this->mappedAspects($item, $metadataMarketplaceId, $categoryId, $categoryTreeId);
        $productReferences = ProductReference::query()
            ->where('company_id', $companyId)
            ->where('channel', EbayConfiguration::CHANNEL)
            ->where('item_id', $item->id)
            ->get();
        $aspectFacts = $this->aspectFacts($item, $metadataMarketplaceId, $categoryId, $categoryTreeId, $productReferences->all(), $existingDraft);
        $identifierAlignment = $this->identifierAlignment($aspectFacts);

        [$blockers, $warnings] = $this->gapAnalyzer->analyze(
            $item,
            [
                'category_id' => $categoryId,
                'policy_ids' => $policyIds,
                'merchant_location_key' => $this->merchantLocationKey($companyId),
                'mapped_aspects' => $mappedAspects,
                'product_reference_count' => $productReferences->count(),
                'metadata_marketplace_id' => $metadataMarketplaceId,
                'seller_marketplace_id' => $sellerMarketplaceId,
                'category_tree_id' => $categoryTreeId,
                'aspect_facts' => $aspectFacts,
                'identifier_alignment' => $identifierAlignment,
            ],
        );

        $readinessStatus = $blockers === [] ? self::STATUS_READY : self::STATUS_BLOCKED;

        return ListingDraft::query()->updateOrCreate(
            [
                'company_id' => $companyId,
                'item_id' => $item->id,
                'channel' => EbayConfiguration::CHANNEL,
                'marketplace_id' => $sellerMarketplaceId,
            ],
            [
                'metadata_marketplace_id' => $metadataMarketplaceId,
                'external_sku' => $item->sku,
                'title' => $item->title,
                'category_id' => $categoryId,
                'status' => ListingDraft::STATUS_DRAFT,
                'management_state' => ListingDraft::MANAGEMENT_LOCAL,
                'mapped_aspects' => $mappedAspects,
                'policy_ids' => $policyIds,
                'merchant_location_key' => $this->merchantLocationKey($companyId),
                'photo_asset_ids' => $item->photos->pluck('media_asset_id')->values()->all(),
                'readiness_status' => $readinessStatus,
                'readiness_snapshot' => [
                    'blockers' => $blockers,
                    'warnings' => $warnings,
                    'facts' => [
                        'listing_marketplace_id' => $sellerMarketplaceId,
                        'metadata_marketplace_id' => $metadataMarketplaceId,
                        'category_tree_id' => $categoryTreeId,
                        'category_id' => $categoryId,
                        'fitment_count' => $item->fitments->count(),
                        'photo_count' => $item->photos->count(),
                        'mapped_aspect_count' => count($mappedAspects),
                        'product_reference_count' => $productReferences->count(),
                        'public_photo_count' => $this->publicPhotoUrls($item)->count(),
                    ],
                    'aspects' => $aspectFacts,
                    'identifier_alignment' => $identifierAlignment,
                    'product_references' => $productReferences->map(fn (ProductReference $reference): array => [
                        'type' => $reference->reference_type,
                        'external_product_id' => $reference->external_product_id,
                        'source' => $reference->source,
                        'review_status' => $reference->review_status,
                    ])->values()->all(),
                ],
                'metadata_checked_at' => Carbon::now(),
                'metadata_version_key' => $this->metadataVersionKey($metadataMarketplaceId, $categoryTreeId, $categoryId),
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
        $pluginMapping = $item->productTemplate !== null
            ? $this->commercePlugins->marketplaceTemplateMappingForTemplate(EbayConfiguration::CHANNEL, $item->productTemplate)
            : [];

        return collect([
            'marketplace_id' => data_get($metadata, 'marketplace.ebay.marketplace_id') ?? ($pluginMapping['marketplace_id'] ?? null),
            'category_tree_id' => data_get($metadata, 'marketplace.ebay.category_tree_id') ?? ($pluginMapping['category_tree_id'] ?? null),
            'category_id' => data_get($metadata, 'marketplace.ebay.category_id') ?? ($pluginMapping['category_id'] ?? null),
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
        return collect($this->aspectFacts($item, $marketplaceId, $categoryId, $categoryTreeId, [], null))
            ->where('source', 'catalog_attribute')
            ->filter(fn (array $fact): bool => $fact['value'] !== null && ($fact['validation'] ?? 'ok') === 'ok')
            ->mapWithKeys(fn (array $fact): array => [$fact['name'] => $fact['normalized_value'] ?? $fact['value']])
            ->all();
    }

    /**
     * @param  list<ProductReference>  $productReferences
     * @return list<array{name: string, value: string|null, normalized_value?: string|null, source: string, confidence: string, internal_attribute_code?: string, validation?: string, message?: string}>
     */
    private function aspectFacts(Item $item, string $marketplaceId, ?string $categoryId, ?string $categoryTreeId, array $productReferences, ?ListingDraft $existingDraft): array
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

        $draftFacts = $this->draftAspectFacts($existingDraft, []);
        $suggestedFacts = collect($productReferences)
            ->flatMap(fn (ProductReference $reference): array => $this->suggestedAspectFacts($reference, []))
            ->values()
            ->all();

        return [...$mappedFacts, ...$draftFacts, ...$suggestedFacts];
    }

    private function existingDraft(int $companyId, int $itemId, string $sellerMarketplaceId): ?ListingDraft
    {
        return ListingDraft::query()
            ->where('company_id', $companyId)
            ->where('item_id', $itemId)
            ->where('channel', EbayConfiguration::CHANNEL)
            ->where('marketplace_id', $sellerMarketplaceId)
            ->first();
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

    /**
     * @param  list<array{name: string, value: string|null, normalized_value?: string|null, source: string, confidence: string, internal_attribute_code?: string, validation?: string, message?: string}>  $aspectFacts
     * @return list<array{key: string, label: string, status: string, sources: array<string, list<string>>}>
     */
    private function identifierAlignment(array $aspectFacts): array
    {
        $groups = [
            [
                'key' => 'brand',
                'label' => 'Brand',
                'names' => ['Brand'],
            ],
            [
                'key' => 'part_number',
                'label' => 'Part Number',
                'names' => self::PART_NUMBER_ASPECT_NAMES,
            ],
        ];

        return collect($groups)
            ->map(function (array $group) use ($aspectFacts): ?array {
                $sources = collect($aspectFacts)
                    ->filter(fn (array $fact): bool => $fact['value'] !== null && in_array($fact['name'], $group['names'], true))
                    ->groupBy('source')
                    ->map(fn (Collection $facts): array => $facts
                        ->map(fn (array $fact): string => trim((string) ($fact['normalized_value'] ?? $fact['value'])))
                        ->filter(fn (string $value): bool => $value !== '')
                        ->unique()
                        ->values()
                        ->all())
                    ->filter(fn (array $values): bool => $values !== [])
                    ->all();

                if ($sources === []) {
                    return null;
                }

                $valueSets = collect($sources)
                    ->map(fn (array $values): array => collect($values)->map(fn (string $value): string => strtolower($value))->sort()->values()->all())
                    ->values();

                return [
                    'key' => $group['key'],
                    'label' => $group['label'],
                    'status' => $this->alignmentStatus($valueSets),
                    'sources' => $sources,
                ];
            })
            ->filter()
            ->values()
            ->all();
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
            AspectMapping::NORMALIZATION_BOOLEAN => $this->normalizeBooleanAspectValue($value),
            default => trim($value),
        };
    }

    private function normalizeBooleanAspectValue(string $value): string
    {
        $normalized = strtolower($value);

        if (in_array($normalized, ['1', 'true', 'yes', 'y'], true)) {
            return 'Yes';
        }

        if (in_array($normalized, ['0', 'false', 'no', 'n'], true)) {
            return 'No';
        }

        return $value;
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
    private function draftAspectFacts(?ListingDraft $draft, array $alreadyMappedNames): array
    {
        $aspectValues = $draft?->aspect_values ?? [];

        if (! is_array($aspectValues)) {
            return [];
        }

        return collect($aspectValues)
            ->map(fn (mixed $entry, mixed $name): ?array => $this->draftAspectFact($entry, $name, $alreadyMappedNames))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $alreadyMappedNames
     * @return array{name: string, value: string, source: string, confidence: string}|null
     */
    private function draftAspectFact(mixed $entry, mixed $name, array $alreadyMappedNames): ?array
    {
        if (! is_string($name) || trim($name) === '' || in_array($name, $alreadyMappedNames, true)) {
            return null;
        }

        $source = is_array($entry) && is_string($entry['source'] ?? null)
            ? trim((string) $entry['source'])
            : 'seller';
        $value = is_array($entry) ? ($entry['value'] ?? null) : $entry;
        $value = is_array($value) ? reset($value) : $value;

        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return [
            'name' => trim($name),
            'value' => trim((string) $value),
            'source' => $source,
            'confidence' => $source === 'ebay_listing'
                ? AspectMapping::CONFIDENCE_IMPORTED
                : AspectMapping::CONFIDENCE_MANUAL,
        ];
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

    private function alignmentStatus(Collection $valueSets): string
    {
        if ($valueSets->count() < 2) {
            return 'partial';
        }

        return $valueSets->unique(fn (array $values): string => json_encode($values))->count() === 1
            ? 'aligned'
            : 'conflict';
    }
}
