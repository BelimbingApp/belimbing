<?php

namespace App\Modules\Commerce\Marketplace\Ebay;

use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Marketplace\Models\ListingDraft;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class EbayListingQualityScorer
{
    /**
     * @return array{score: int, label: string, status: string, variant: string}
     */
    public function baselineQuality(Listing $listing): array
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
    public function currentQuality(Listing $listing, ?ListingDraft $draft, array $sales, Collection $trustSignals): array
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
}
