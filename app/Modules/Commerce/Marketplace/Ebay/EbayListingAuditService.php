<?php

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Base\Foundation\ValueObjects\Money;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Marketplace\Models\ListingDraft;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EbayListingAuditService
{
    /**
     * @var list<string>
     */
    private const ACTIVE_LISTING_STATUSES = [
        'ACTIVE',
        'PUBLISHED',
    ];

    /**
     * @return array{
     *     totalListings: int,
     *     linkedListings: int,
     *     unlinkedListings: int,
     *     externallyChangedListings: int,
     *     readyToAdoptListings: int,
     *     missingFitmentListings: int,
     *     conflictingIdentifierListings: int,
     *     missingIdentifierListings: int,
     *     driftedListings: int
     * }
     */
    public function stats(Collection $listings): array
    {
        return [
            'totalListings' => $listings->count(),
            'linkedListings' => $listings->whereNotNull('item_id')->count(),
            'unlinkedListings' => $listings->whereNull('item_id')->count(),
            'externallyChangedListings' => $listings->filter(
                fn (Listing $listing): bool => $this->state($listing) === Listing::RECONCILIATION_EXTERNALLY_CHANGED
            )->count(),
            'readyToAdoptListings' => $listings->filter(
                fn (Listing $listing): bool => $this->state($listing) === Listing::RECONCILIATION_READY_TO_ADOPT
            )->count(),
            'missingFitmentListings' => $listings->filter(
                fn (Listing $listing): bool => $this->state($listing) === Listing::RECONCILIATION_MISSING_FITMENT
            )->count(),
            'conflictingIdentifierListings' => $listings->filter(
                fn (Listing $listing): bool => $this->state($listing) === Listing::RECONCILIATION_CONFLICTING_IDENTIFIERS
            )->count(),
            'missingIdentifierListings' => $listings->filter(
                fn (Listing $listing): bool => $this->state($listing) === Listing::RECONCILIATION_MISSING_IDENTIFIERS
            )->count(),
            'driftedListings' => $listings->filter(
                fn (Listing $listing): bool => $this->state($listing) === Listing::RECONCILIATION_DRIFTED
            )->count(),
        ];
    }

    public function state(Listing $listing): string
    {
        $listing->loadMissing(['item', 'draft']);

        if ($listing->item_id === null) {
            return Listing::RECONCILIATION_UNLINKED;
        }

        if ($listing->isExternallyChanged()) {
            return Listing::RECONCILIATION_EXTERNALLY_CHANGED;
        }

        if ($listing->isImported()) {
            $draft = $listing->draft;

            if ($draft instanceof ListingDraft && $draft->hasGapKey('fitment')) {
                return Listing::RECONCILIATION_MISSING_FITMENT;
            }

            if ($draft instanceof ListingDraft && $draft->hasIdentifierConflict()) {
                return Listing::RECONCILIATION_CONFLICTING_IDENTIFIERS;
            }

            if ($draft instanceof ListingDraft && $draft->hasAnyGapKey([
                'identifier_brand',
                'identifier_part_number',
                'product_reference',
            ])) {
                return Listing::RECONCILIATION_MISSING_IDENTIFIERS;
            }

            return Listing::RECONCILIATION_READY_TO_ADOPT;
        }

        if ($this->isOperationallyDrifted($listing)) {
            return Listing::RECONCILIATION_DRIFTED;
        }

        return Listing::RECONCILIATION_MANAGED;
    }

    public function label(Listing $listing): string
    {
        return match ($this->state($listing)) {
            Listing::RECONCILIATION_UNLINKED => __('Unlinked'),
            Listing::RECONCILIATION_EXTERNALLY_CHANGED => __('Externally Changed'),
            Listing::RECONCILIATION_MISSING_FITMENT => __('Missing Fitment'),
            Listing::RECONCILIATION_CONFLICTING_IDENTIFIERS => __('Conflicting Identifiers'),
            Listing::RECONCILIATION_MISSING_IDENTIFIERS => __('Missing Identifiers'),
            Listing::RECONCILIATION_READY_TO_ADOPT => __('Ready to Adopt'),
            Listing::RECONCILIATION_DRIFTED => __('Drifted'),
            default => __('Managed'),
        };
    }

    public function variant(Listing $listing): string
    {
        return match ($this->state($listing)) {
            Listing::RECONCILIATION_EXTERNALLY_CHANGED,
            Listing::RECONCILIATION_MISSING_FITMENT,
            Listing::RECONCILIATION_CONFLICTING_IDENTIFIERS,
            Listing::RECONCILIATION_DRIFTED => 'danger',
            Listing::RECONCILIATION_UNLINKED,
            Listing::RECONCILIATION_MISSING_IDENTIFIERS => 'warning',
            default => 'success',
        };
    }

    public function managementStateVariant(string $state): string
    {
        return match ($state) {
            Listing::MANAGEMENT_BELIMBING_MANAGED => 'success',
            Listing::MANAGEMENT_IMPORTED => 'default',
            default => 'default',
        };
    }

    public function listingStatusVariant(?string $status): string
    {
        return in_array(Str::upper((string) $status), self::ACTIVE_LISTING_STATUSES, true)
            ? 'success'
            : 'default';
    }

    public function itemStatusVariant(?string $status): string
    {
        return match ($status) {
            Item::STATUS_DRAFT => 'default',
            Item::STATUS_READY => 'info',
            Item::STATUS_LISTED => 'accent',
            Item::STATUS_SOLD => 'success',
            Item::STATUS_ARCHIVED => 'default',
            default => 'default',
        };
    }

    public function formatMoney(?int $amount, ?string $currencyCode): string
    {
        if ($amount === null || $currencyCode === null || $currencyCode === '') {
            return __('n/a');
        }

        return Money::format($amount, $currencyCode);
    }

    private function isOperationallyDrifted(Listing $listing): bool
    {
        $item = $listing->item;

        if (! $item instanceof Item) {
            return false;
        }

        $listingActive = in_array(Str::upper((string) $listing->status), self::ACTIVE_LISTING_STATUSES, true);

        if ($listingActive && $item->status !== Item::STATUS_LISTED) {
            return true;
        }

        if (! $listingActive && $item->status === Item::STATUS_LISTED) {
            return true;
        }

        if (
            $item->target_price_amount !== null
            && $listing->price_amount !== null
            && $item->target_price_amount !== $listing->price_amount
        ) {
            return true;
        }

        return $item->currency_code !== null
            && $listing->currency_code !== null
            && $item->currency_code !== $listing->currency_code;
    }
}
