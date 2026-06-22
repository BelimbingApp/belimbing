<?php

namespace App\Base\Software\Services;

use App\Base\Software\Inventory\Contracts\InventoryContributionProvider;
use App\Base\Software\Inventory\ContributionSummary;

/**
 * Collects the contribution-summary providers published by host module seams and
 * flattens their summaries for the Software Inventory. A singleton seeded at boot by
 * InventoryContributionDiscoveryService.
 */
class InventoryContributionRegistry
{
    /** @var list<InventoryContributionProvider> */
    private array $providers = [];

    public function register(InventoryContributionProvider $provider): void
    {
        $this->providers[] = $provider;
    }

    /**
     * @return list<ContributionSummary>
     */
    public function contributions(): array
    {
        $summaries = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->contributions() as $summary) {
                if ($summary instanceof ContributionSummary) {
                    $summaries[] = $summary;
                }
            }
        }

        return $summaries;
    }
}
