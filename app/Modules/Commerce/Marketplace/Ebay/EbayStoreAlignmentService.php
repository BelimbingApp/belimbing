<?php

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Modules\Commerce\Catalog\Models\Description;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Marketplace\Models\ListingDraft;
use App\Modules\Commerce\Sales\Models\Order;
use App\Modules\Commerce\Sales\Models\Sale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EbayStoreAlignmentService
{
    /**
     * @var list<array{key: string, label: string, names: list<string>}>
     */
    private const SPECIFIC_ALIGNMENT_GROUPS = [
        ['key' => 'brand', 'label' => 'Brand', 'names' => ['Brand']],
        ['key' => 'part_number', 'label' => 'Part Number', 'names' => ['Manufacturer Part Number', 'MPN', 'OE/OEM Part Number', 'Interchange Part Number']],
        ['key' => 'type', 'label' => 'Type', 'names' => ['Type']],
        ['key' => 'finish', 'label' => 'Finish', 'names' => ['Finish']],
        ['key' => 'placement', 'label' => 'Placement', 'names' => ['Placement on Vehicle']],
        ['key' => 'piston_count', 'label' => 'Piston Count', 'names' => ['Piston Quantity', 'Piston Count']],
        ['key' => 'performance_part', 'label' => 'Performance Part', 'names' => ['Performance Part']],
        ['key' => 'universal_fit', 'label' => 'Universal Fit', 'names' => ['Universal Fitment', 'Universal']],
    ];

    public function __construct(
        private readonly EbayListingAuditService $audit,
    ) {}

    /**
     * @return array{
     *     cleanupQueue: Collection<int, array<string, mixed>>,
     *     qualitySummary: array<string, int>,
     *     trustSignals: Collection<int, array<string, mixed>>,
     *     fitmentBatchCandidates: Collection<int, array<string, mixed>>
     * }
     */
    public function dashboard(int $companyId, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();

        /** @var Collection<int, Listing> $listings */
        $listings = Listing::query()
            ->where('company_id', $companyId)
            ->where('channel', EbayConfiguration::CHANNEL)
            ->whereNotNull('item_id')
            ->with([
                'item.photos.mediaAsset',
                'item.fitments',
                'item.descriptions',
                'item.marketplaceListings',
                'draft',
            ])
            ->get();

        $salesByListing = $this->salesByListing($companyId);
        $trustSignals = $this->trustSignals($companyId, $listings);
        $trustSignalsByListing = $trustSignals->groupBy('listing_id');

        $cleanupQueue = $listings
            ->map(fn (Listing $listing): array => $this->cleanupRow(
                listing: $listing,
                sales: $salesByListing[$listing->id] ?? ['sale_count' => 0, 'last_sold_at' => null],
                trustSignals: collect($trustSignalsByListing->get($listing->id, [])),
                asOf: $asOf,
            ))
            ->sortByDesc('impact_score')
            ->values();

        return [
            'cleanupQueue' => $cleanupQueue->take(12)->values(),
            'qualitySummary' => $this->qualitySummary($cleanupQueue),
            'trustSignals' => $trustSignals
                ->sortByDesc('severity_score')
                ->take(10)
                ->values(),
            'fitmentBatchCandidates' => $this->fitmentBatchCandidates($companyId)->take(10)->values(),
        ];
    }

    /**
     * @param  array{sale_count: int, last_sold_at: Carbon|null}  $sales
     * @return array<string, mixed>
     */
    private function cleanupRow(Listing $listing, array $sales, Collection $trustSignals, Carbon $asOf): array
    {
        $draft = $listing->draft;
        $item = $listing->item;
        $state = $this->audit->state($listing);
        $baselineQuality = $this->baselineQuality($listing);
        $currentQuality = $this->currentQuality($listing, $draft, $sales, $trustSignals);
        $performance = [
            'sale_count' => $sales['sale_count'],
            'last_sold_at' => $sales['last_sold_at'],
            'inventory_age_days' => $item?->created_at?->diffInDays($asOf) ?? null,
            'listed_age_days' => $listing->listed_at?->diffInDays($asOf),
            'buyer_signal_count' => $trustSignals->count(),
        ];
        $recommendations = $this->recommendations($listing, $draft, $performance, $trustSignals);
        $issues = $this->issueLabels($listing, $draft);
        $specificAlignment = $this->specificAlignment($draft);
        $impactScore = $this->impactScore(
            listing: $listing,
            draft: $draft,
            recommendations: $recommendations,
            performance: $performance,
            trustSignals: $trustSignals,
            currentQuality: $currentQuality,
        );

        return [
            'listing' => $listing,
            'state' => $state,
            'state_label' => $this->audit->label($listing),
            'state_variant' => $this->audit->variant($listing),
            'impact_score' => $impactScore,
            'import_audit' => $this->importAudit($listing, $draft),
            'issues' => $issues,
            'recommendations' => $recommendations,
            'specific_alignment' => $specificAlignment,
            'baseline_quality' => $baselineQuality,
            'current_quality' => $currentQuality,
            'quality_delta' => $currentQuality['score'] - $baselineQuality['score'],
            'performance' => $performance,
            'trust_signals' => $trustSignals->values(),
        ];
    }

    /**
     * @return array<int, array{sale_count: int, last_sold_at: Carbon|null}>
     */
    private function salesByListing(int $companyId): array
    {
        return Sale::query()
            ->where('company_id', $companyId)
            ->where('channel', EbayConfiguration::CHANNEL)
            ->whereNotNull('listing_id')
            ->get(['listing_id', 'sold_at'])
            ->groupBy('listing_id')
            ->map(fn (Collection $sales): array => [
                'sale_count' => $sales->count(),
                'last_sold_at' => $sales
                    ->pluck('sold_at')
                    ->filter()
                    ->map(fn ($soldAt): Carbon => $soldAt instanceof Carbon ? $soldAt : Carbon::parse($soldAt))
                    ->sortDesc()
                    ->first(),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, Listing>  $listings
     * @return Collection<int, array<string, mixed>>
     */
    private function trustSignals(int $companyId, Collection $listings): Collection
    {
        $listingById = $listings->keyBy('id');
        $listingIdByExternalId = $listings
            ->filter(fn (Listing $listing): bool => $listing->external_listing_id !== null)
            ->mapWithKeys(fn (Listing $listing): array => [$listing->external_listing_id => $listing->id]);

        return Order::query()
            ->where('company_id', $companyId)
            ->where('channel', EbayConfiguration::CHANNEL)
            ->with('lines')
            ->get()
            ->flatMap(fn (Order $order): array => $this->trustSignalsForOrder($order, $listingById, $listingIdByExternalId))
            ->values();
    }

    /**
     * @param  Collection<int, Listing>  $listingById
     * @param  Collection<string, int>  $listingIdByExternalId
     * @return list<array<string, mixed>>
     */
    private function trustSignalsForOrder(Order $order, Collection $listingById, Collection $listingIdByExternalId): array
    {
        $signals = [];
        $message = $this->firstString([
            data_get($order->raw_payload, 'buyerCheckoutNotes'),
            data_get($order->raw_payload, 'buyerMessage'),
            data_get($order->raw_payload, 'buyer.buyerMessage'),
        ]);

        foreach ($order->lines as $line) {
            $listingId = $line->listing_id
                ?? $listingIdByExternalId->get($line->external_listing_id);

            if ($listingId === null || ! $listingById->has($listingId)) {
                continue;
            }

            $listing = $listingById->get($listingId);
            $title = $listing?->title ?? $line->title ?? $line->external_listing_id;

            if ($message !== null) {
                $signals[] = $this->buyerQuestionSignal($order, $listingId, $title, $message);
            }

            if ($this->hasReturnOrCancelSignal($order)) {
                $signals[] = $this->returnOrCancelSignal($order, $listingId, $title);
            }
        }

        return $signals;
    }

    /**
     * @return array<string, mixed>
     */
    private function buyerQuestionSignal(Order $order, int $listingId, ?string $title, string $message): array
    {
        return [
            'listing_id' => $listingId,
            'listing_title' => $title,
            'buyer' => $order->buyer_username,
            'ordered_at' => $order->ordered_at,
            'type' => 'buyer_question',
            'label' => 'Buyer question',
            'detail' => Str::limit($message, 120, '...'),
            'severity' => 'warning',
            'severity_score' => 30,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function returnOrCancelSignal(Order $order, int $listingId, ?string $title): array
    {
        $status = strtoupper((string) $order->status);

        return [
            'listing_id' => $listingId,
            'listing_title' => $title,
            'buyer' => $order->buyer_username,
            'ordered_at' => $order->ordered_at,
            'type' => 'return_or_cancel',
            'label' => 'Return / cancellation signal',
            'detail' => $status !== '' ? $status : 'Return or cancel signal found in eBay order payload.',
            'severity' => 'danger',
            'severity_score' => 45,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function fitmentBatchCandidates(int $companyId): Collection
    {
        $sourceByTemplate = Item::query()
            ->where('company_id', $companyId)
            ->whereNotNull('product_template_id')
            ->whereHas('fitments')
            ->withCount('fitments')
            ->orderBy('sku')
            ->get()
            ->groupBy('product_template_id');

        return Item::query()
            ->where('company_id', $companyId)
            ->whereNotNull('product_template_id')
            ->whereDoesntHave('fitments')
            ->orderBy('sku')
            ->get()
            ->map(function (Item $item) use ($sourceByTemplate): ?array {
                $source = $sourceByTemplate
                    ->get($item->product_template_id)
                    ?->first();

                if (! $source instanceof Item) {
                    return null;
                }

                return [
                    'target_item' => $item,
                    'source_item' => $source,
                    'fitment_count' => (int) ($source->fitments_count ?? 0),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $cleanupQueue
     * @return array<string, int>
     */
    private function qualitySummary(Collection $cleanupQueue): array
    {
        return [
            'improved' => $cleanupQueue->filter(fn (array $row): bool => $row['quality_delta'] > 0)->count(),
            'unchanged' => $cleanupQueue->filter(fn (array $row): bool => $row['quality_delta'] === 0)->count(),
            'regressed' => $cleanupQueue->filter(fn (array $row): bool => $row['quality_delta'] < 0)->count(),
            'strong' => $cleanupQueue->filter(fn (array $row): bool => $row['current_quality']['status'] === 'strong')->count(),
            'needs_work' => $cleanupQueue->filter(fn (array $row): bool => in_array($row['current_quality']['status'], ['attention', 'risk'], true))->count(),
        ];
    }

    /**
     * @return array{score: int, label: string, status: string, variant: string}
     */
    private function baselineQuality(Listing $listing): array
    {
        $score = 20;
        $aspects = data_get($listing->raw_payload, 'inventory_item.product.aspects', []);

        if ($listing->hasInventoryItemSnapshot()) {
            $score += 15;
        }

        if ($listing->hasInventoryApiWritePath()) {
            $score += 15;
        }

        if (is_array($aspects) && $aspects !== []) {
            $score += 20;
        }

        if ($this->firstString([
            data_get($listing->raw_payload, 'inventory_item.product.epid'),
            data_get($listing->raw_payload, 'inventory_item.product.brand'),
            data_get($listing->raw_payload, 'inventory_item.product.mpn'),
        ]) !== null) {
            $score += 15;
        }

        if ($listing->item_id !== null) {
            $score += 10;
        }

        return $this->qualityStatus($score);
    }

    /**
     * @param  array{sale_count: int, last_sold_at: Carbon|null}  $sales
     * @return array{score: int, label: string, status: string, variant: string}
     */
    private function currentQuality(Listing $listing, ?ListingDraft $draft, array $sales, Collection $trustSignals): array
    {
        $score = 100;
        $blockers = count(data_get($draft?->readiness_snapshot, 'blockers', []));
        $warnings = count(data_get($draft?->readiness_snapshot, 'warnings', []));

        $score -= $blockers * 12;
        $score -= $warnings * 5;

        if ($listing->adoptionState() === Listing::ADOPTION_LEGACY_RELIST_REQUIRED) {
            $score -= 15;
        }

        if ($listing->isExternallyChanged()) {
            $score -= 12;
        }

        $score -= min(20, $trustSignals->count() * 10);

        if ($sales['sale_count'] > 0) {
            $score += 8;
        }

        return $this->qualityStatus($score);
    }

    /**
     * @return array{score: int, label: string, status: string, variant: string}
     */
    private function qualityStatus(int $score): array
    {
        $score = max(0, min(100, $score));

        return match (true) {
            $score >= 85 => ['score' => $score, 'label' => 'Strong', 'status' => 'strong', 'variant' => 'success'],
            $score >= 65 => ['score' => $score, 'label' => 'Workable', 'status' => 'workable', 'variant' => 'info'],
            $score >= 45 => ['score' => $score, 'label' => 'Attention', 'status' => 'attention', 'variant' => 'warning'],
            default => ['score' => $score, 'label' => 'At Risk', 'status' => 'risk', 'variant' => 'danger'],
        };
    }

    /**
     * @return list<array{label: string, value: string, variant: string}>
     */
    private function importAudit(Listing $listing, ?ListingDraft $draft): array
    {
        $blockerKeys = $draft?->blockerKeys() ?? [];
        $warningKeys = $draft?->warningKeys() ?? [];
        $gapKeys = [...$blockerKeys, ...$warningKeys];
        $specificAlignment = $this->specificAlignment($draft);

        return [
            [
                'label' => 'Compatibility',
                'value' => in_array('fitment', $gapKeys, true) ? 'Missing' : 'Ready',
                'variant' => in_array('fitment', $gapKeys, true) ? 'danger' : 'success',
            ],
            [
                'label' => 'Identifiers',
                'value' => $this->identifierAuditValue($draft),
                'variant' => $this->identifierAuditVariant($draft),
            ],
            [
                'label' => 'Specifics',
                'value' => collect($specificAlignment)->contains(fn (array $row): bool => $row['status'] === 'conflict') ? 'Conflict' : 'Reviewed',
                'variant' => collect($specificAlignment)->contains(fn (array $row): bool => $row['status'] === 'conflict') ? 'danger' : 'success',
            ],
            [
                'label' => 'Policies',
                'value' => $this->hasPolicyCoverageGap($gapKeys) ? 'Missing' : 'Ready',
                'variant' => $this->hasPolicyCoverageGap($gapKeys) ? 'danger' : 'success',
            ],
            [
                'label' => 'Inventory API',
                'value' => $listing->adoptionState() === Listing::ADOPTION_INVENTORY_API_ADOPTABLE ? 'Adoptable' : 'Relist',
                'variant' => $listing->adoptionState() === Listing::ADOPTION_INVENTORY_API_ADOPTABLE ? 'success' : 'danger',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function issueLabels(Listing $listing, ?ListingDraft $draft): array
    {
        $issues = [$this->audit->label($listing)];

        foreach (data_get($draft?->readiness_snapshot, 'blockers', []) as $gap) {
            if (is_array($gap) && is_string($gap['label'] ?? null)) {
                $issues[] = $gap['label'];
            }
        }

        foreach ($this->specificAlignment($draft) as $alignment) {
            if ($alignment['status'] === 'conflict') {
                $issues[] = $alignment['label'].' conflict';
            }
        }

        return collect($issues)->unique()->values()->take(3)->all();
    }

    /**
     * @param  array{sale_count: int, last_sold_at: Carbon|null, inventory_age_days: int|null, listed_age_days: int|null, buyer_signal_count: int}  $performance
     * @return list<string>
     */
    private function recommendations(Listing $listing, ?ListingDraft $draft, array $performance, Collection $trustSignals): array
    {
        $recommendations = [];
        $warningKeys = $draft?->warningKeys() ?? [];
        $blockerKeys = $draft?->blockerKeys() ?? [];
        $gapKeys = [...$blockerKeys, ...$warningKeys];
        $item = $listing->item;

        if (in_array('fitment', $gapKeys, true)) {
            $suggestedFitment = $item instanceof Item ? $this->fitmentSuggestion($item) : null;
            $recommendations[] = $suggestedFitment !== null
                ? 'Review suggested fitment: '.$suggestedFitment
                : 'Add or copy fitment before adopting this listing.';
        }

        if (in_array('identifier_part_number', $gapKeys, true)) {
            $importedSuggestion = $this->firstImportedAspectSuggestion($draft, ['Manufacturer Part Number', 'MPN', 'OE/OEM Part Number', 'Interchange Part Number']);
            $recommendations[] = $importedSuggestion !== null
                ? 'Review imported part number: '.$importedSuggestion
                : 'Add manufacturer, OEM, or interchange part numbers.';
        }

        if (in_array('title_guidance', $gapKeys, true)) {
            $recommendations[] = 'Tighten the title with brand, part type, placement, and part number.';
        }

        if (collect($gapKeys)->intersect(['photos', 'publish_safe_photos', 'photo_coverage'])->isNotEmpty()) {
            $recommendations[] = 'Add more publish-safe photos with labels, mounts, connectors, and defects.';
        }

        if (($performance['inventory_age_days'] ?? 0) >= 45 && $performance['sale_count'] === 0) {
            $recommendations[] = 'Review pricing, title, and fitment for aging stock with no sale yet.';
        }

        if ($this->hasUsedPartDisclosureGap($draft, $item)) {
            $recommendations[] = 'Add warranty/return clarity and defect disclosure for this used part.';
        }

        if ($trustSignals->isNotEmpty()) {
            $recommendations[] = 'Review buyer questions or returns for fitment ambiguity before revising.';
        }

        return collect($recommendations)->unique()->values()->take(4)->all();
    }

    /**
     * @param  array{sale_count: int, last_sold_at: Carbon|null, inventory_age_days: int|null, listed_age_days: int|null, buyer_signal_count: int}  $performance
     */
    private function impactScore(
        Listing $listing,
        ?ListingDraft $draft,
        array $recommendations,
        array $performance,
        Collection $trustSignals,
        array $currentQuality,
    ): int {
        $score = 0;
        $state = $this->audit->state($listing);

        $score += match ($state) {
            Listing::RECONCILIATION_MISSING_FITMENT => 60,
            Listing::RECONCILIATION_LEGACY_RELIST_REQUIRED => 58,
            Listing::RECONCILIATION_CONFLICTING_IDENTIFIERS => 50,
            Listing::RECONCILIATION_MISSING_IDENTIFIERS => 40,
            Listing::RECONCILIATION_EXTERNALLY_CHANGED => 45,
            default => 20,
        };

        $score += count($draft?->blockerKeys() ?? []) * 6;
        $score += count($recommendations) * 4;
        $score += min(20, (int) floor((($listing->item?->target_price_amount ?? $listing->price_amount ?? 0) / 10000)));
        $score += (($performance['inventory_age_days'] ?? 0) >= 45 && $performance['sale_count'] === 0) ? 12 : 0;
        $score += min(15, $trustSignals->count() * 5);
        $score += max(0, 70 - $currentQuality['score']) / 4;

        return (int) round($score);
    }

    /**
     * @return list<array{key: string, label: string, status: string, summary: string}>
     */
    private function specificAlignment(?ListingDraft $draft): array
    {
        $facts = collect(data_get($draft?->readiness_snapshot, 'aspects', []))
            ->filter(fn (mixed $fact): bool => is_array($fact) && is_string($fact['name'] ?? null))
            ->values();

        return collect(self::SPECIFIC_ALIGNMENT_GROUPS)
            ->map(function (array $group) use ($facts): ?array {
                $sources = $facts
                    ->filter(fn (array $fact): bool => in_array($fact['name'], $group['names'], true) && ($fact['value'] ?? null) !== null)
                    ->groupBy('source')
                    ->map(fn (Collection $rows): array => $rows
                        ->map(fn (array $row): string => trim((string) ($row['normalized_value'] ?? $row['value'])))
                        ->filter(fn (string $value): bool => $value !== '')
                        ->unique()
                        ->values()
                        ->all())
                    ->filter(fn (array $values): bool => $values !== []);

                if ($sources->isEmpty()) {
                    return null;
                }

                $valueSets = $sources
                    ->map(fn (array $values): array => collect($values)->map(fn (string $value): string => strtolower($value))->sort()->values()->all())
                    ->values();

                return [
                    'key' => $group['key'],
                    'label' => $group['label'],
                    'status' => $this->alignmentStatus($valueSets),
                    'summary' => $sources
                        ->map(fn (array $values, string $source): string => Str::headline(str_replace('_', ' ', $source)).': '.implode(', ', $values))
                        ->implode(' | '),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function identifierAuditValue(?ListingDraft $draft): string
    {
        if ($draft?->hasIdentifierConflict()) {
            return 'Conflict';
        }

        if ($draft?->hasAnyGapKey(['identifier_brand', 'identifier_part_number', 'product_reference'])) {
            return 'Missing';
        }

        return 'Ready';
    }

    private function identifierAuditVariant(?ListingDraft $draft): string
    {
        if ($draft?->hasIdentifierConflict()) {
            return 'danger';
        }

        if ($draft?->hasAnyGapKey(['identifier_brand', 'identifier_part_number', 'product_reference'])) {
            return 'warning';
        }

        return 'success';
    }

    /**
     * @param  list<string>  $gapKeys
     */
    private function hasPolicyCoverageGap(array $gapKeys): bool
    {
        return collect($gapKeys)->contains(fn (string $key): bool => str_starts_with($key, 'policy_') || str_starts_with($key, 'merchant_location'));
    }

    private function fitmentSuggestion(Item $item): ?string
    {
        $text = trim($item->title.' '.$this->acceptedDescriptionBody($item));

        if ($text === '') {
            return null;
        }

        if (preg_match('/\b((?:19|20)\d{2})\s+([A-Z][A-Za-z0-9-]+)\s+([A-Z0-9][A-Za-z0-9-]+)/', $text, $matches) === 1) {
            return trim($matches[1].' '.$matches[2].' '.$matches[3]);
        }

        return null;
    }

    /**
     * @param  list<string>  $names
     */
    private function firstImportedAspectSuggestion(?ListingDraft $draft, array $names): ?string
    {
        return collect(data_get($draft?->readiness_snapshot, 'aspects', []))
            ->first(function (mixed $fact) use ($names): bool {
                return is_array($fact)
                    && in_array($fact['source'] ?? null, ['ebay_listing', 'ebay_product_reference'], true)
                    && in_array($fact['name'] ?? null, $names, true)
                    && is_string($fact['value'] ?? null)
                    && trim((string) $fact['value']) !== '';
            })['value'] ?? null;
    }

    private function hasUsedPartDisclosureGap(?ListingDraft $draft, ?Item $item): bool
    {
        $condition = strtolower((string) ($draft?->mapped_aspects['Condition'] ?? $draft?->mapped_aspects['Condition Grade'] ?? ''));

        if (! str_contains($condition, 'used')) {
            return false;
        }

        $description = strtolower($this->acceptedDescriptionBody($item));

        if ($description === '') {
            return true;
        }

        $hasReturnLanguage = preg_match('/return|warranty/', $description) === 1;
        $hasConditionLanguage = preg_match('/defect|scratch|crack|chip|blemish|tested|untested/', $description) === 1;

        return ! $hasReturnLanguage || ! $hasConditionLanguage;
    }

    private function acceptedDescriptionBody(?Item $item): string
    {
        if (! $item instanceof Item) {
            return '';
        }

        /** @var Description|null $description */
        $description = $item->descriptions
            ->first(fn (Description $description): bool => $description->is_accepted)
            ?? $item->descriptions->first();

        return trim((string) $description?->body);
    }

    private function hasReturnOrCancelSignal(Order $order): bool
    {
        $haystacks = [
            strtoupper((string) $order->status),
            strtoupper((string) data_get($order->raw_payload, 'cancelStatus')),
            strtoupper((string) data_get($order->raw_payload, 'returnStatus')),
            strtoupper((string) data_get($order->raw_payload, 'orderPaymentStatus')),
            strtoupper((string) data_get($order->raw_payload, 'orderFulfillmentStatus')),
        ];

        return collect($haystacks)->contains(fn (string $value): bool => Str::contains($value, ['RETURN', 'REFUND', 'CANCEL']));
    }

    /**
     * @param  list<mixed>  $candidates
     */
    private function firstString(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
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
