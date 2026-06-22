<?php

namespace App\Base\Software\Inventory\Contracts;

use App\Base\Software\Inventory\ContributionSummary;

/**
 * Implemented by a host module's extension seam to report its discovered runtime
 * contributions to the Software Inventory. Read-only: the inventory displays what a
 * seam returns and never drives the contribution's behavior.
 *
 * A module advertises its provider by listing the class under `contribution_providers`
 * in its `Config/inventory.php`; InventoryContributionDiscoveryService registers it.
 */
interface InventoryContributionProvider
{
    /**
     * @return list<ContributionSummary>
     */
    public function contributions(): array;
}
