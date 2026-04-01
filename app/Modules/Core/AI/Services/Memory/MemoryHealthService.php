<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Memory;

use App\Base\AI\Services\VectorStoreService;
use App\Modules\Core\AI\DTO\MemoryHealthReport;

/**
 * Reports memory subsystem health for an agent.
 *
 * Consults the source catalog, index store, and vector store
 * to produce a diagnostic summary for operators and tools.
 */
class MemoryHealthService
{
    public function __construct(
        private readonly MemorySourceCatalog $catalog,
    ) {}

    /**
     * Generate a health report for an agent's memory.
     */
    public function report(int $employeeId): MemoryHealthReport
    {
        $sources = $this->catalog->scan($employeeId);
        $store = MemoryIndexStore::forAgent($employeeId);

        if (! $store->exists()) {
            return new MemoryHealthReport(
                employeeId: $employeeId,
                indexed: false,
                sourceCount: count($sources),
                chunkCount: 0,
                staleSourceCount: count($sources),
                lastIndexedAt: null,
                lastCompactedAt: null,
                embeddingsAvailable: VectorStoreService::isSqliteVecAvailable(),
            );
        }

        $store->ensureSchema();
        $manifestEntries = $store->allManifestEntries();

        // Count stale sources: sources whose hash doesn't match manifest
        $manifestMap = [];

        foreach ($manifestEntries as $entry) {
            $manifestMap[$entry->relativePath] = $entry->contentHash;
        }

        $staleCount = 0;

        foreach ($sources as $source) {
            $indexed = $manifestMap[$source->relativePath] ?? null;

            if ($indexed === null || $indexed !== $source->contentHash) {
                $staleCount++;
            }
        }

        $lastIndexed = $store->getMeta('last_indexed_at');
        $lastCompacted = $store->getMeta('last_compacted_at');

        return new MemoryHealthReport(
            employeeId: $employeeId,
            indexed: true,
            sourceCount: count($sources),
            chunkCount: $store->chunkCount(),
            staleSourceCount: $staleCount,
            lastIndexedAt: $lastIndexed !== null ? (int) $lastIndexed : null,
            lastCompactedAt: $lastCompacted !== null ? (int) $lastCompacted : null,
            embeddingsAvailable: VectorStoreService::isSqliteVecAvailable(),
        );
    }
}
