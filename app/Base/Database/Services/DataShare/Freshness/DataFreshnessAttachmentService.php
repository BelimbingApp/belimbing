<?php

namespace App\Base\Database\Services\DataShare\Freshness;

use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorCatalogTable;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorCatalog;

/**
 * Phase 4 attachment: attach proven freshness tracking triggers to ordinary
 * eligible Local PostgreSQL tables and reconcile as registry membership
 * changes. This is invoked explicitly (never on migrate) and is gated by the
 * Phase 3 go/no-go — on any non-PostgreSQL driver it attaches nothing.
 *
 * Eligibility mirrors the catalog's own rules: a supported, registered ordinary
 * Local table. Audit, schedule, ledger, observation and generation
 * infrastructure are excluded because the catalog already marks them protected.
 */
final class DataFreshnessAttachmentService
{
    public function __construct(
        private readonly DataFreshnessTracker $tracker,
        private readonly DataShareMirrorCatalog $catalog,
    ) {}

    /**
     * @return array{driver_supported: bool, attached: list<string>}
     */
    public function attachEligible(): array
    {
        if (! $this->tracker->driverSupportsTracking()) {
            return ['driver_supported' => false, 'attached' => []];
        }

        $eligible = $this->eligibleTables();

        foreach ($eligible as $table) {
            $this->tracker->installTracking($table);
        }

        return ['driver_supported' => true, 'attached' => $eligible];
    }

    /**
     * @return list<string>
     */
    public function eligibleTables(): array
    {
        // Trigger attachment is a Local concern. It must not open the remote or
        // make eligibility depend on remote availability/ownership drift.
        return collect($this->catalog->localCatalog())
            ->filter(fn (DataShareMirrorCatalogTable $table): bool => $table->supported
                && $table->localExists
                && $table->localKind === 'table')
            ->map(fn (DataShareMirrorCatalogTable $table): string => $table->table)
            ->values()
            ->all();
    }
}
