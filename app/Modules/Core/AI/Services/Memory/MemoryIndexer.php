<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Memory;

use App\Modules\Core\AI\DTO\MemoryIndexManifestEntry;
use App\Modules\Core\AI\DTO\MemorySourceEntry;

/**
 * Builds and refreshes the per-agent memory index.
 *
 * Orchestrates source catalog → chunker → index store.
 * On each run, only re-indexes sources whose content hash differs
 * from the manifest entry, and removes stale entries for deleted sources.
 *
 * Key invariant: markdown is canonical; the index is always rebuildable.
 */
class MemoryIndexer
{
    public function __construct(
        private readonly MemorySourceCatalog $catalog,
        private readonly MemoryChunker $chunker,
    ) {}

    /**
     * Index all memory sources for an agent.
     *
     * Returns a summary of what was done.
     *
     * @return array{indexed: int, skipped: int, stale_removed: int, total_chunks: int}
     */
    public function index(int $employeeId): array
    {
        $sources = $this->catalog->scan($employeeId);
        $store = MemoryIndexStore::forAgent($employeeId);
        $store->ensureSchema();

        $indexed = 0;
        $skipped = 0;

        $currentPaths = array_map(
            fn (MemorySourceEntry $s): string => $s->relativePath,
            $sources,
        );

        $store->beginTransaction();

        try {
            // Remove entries for sources that no longer exist
            $staleRemoved = $store->removeStaleEntries($currentPaths);

            // Index or skip each source
            foreach ($sources as $source) {
                $existing = $store->manifestEntry($source->relativePath);

                if ($existing !== null && $existing->contentHash === $source->contentHash) {
                    $skipped++;

                    continue;
                }

                $this->indexSource($source, $store);
                $indexed++;
            }

            $store->setMeta('last_indexed_at', (string) time());
            $store->commit();
        } catch (\Throwable $e) {
            $store->rollBack();

            throw $e;
        }

        return [
            'indexed' => $indexed,
            'skipped' => $skipped,
            'stale_removed' => $staleRemoved,
            'total_chunks' => $store->chunkCount(),
        ];
    }

    /**
     * Force reindex all sources (ignores content hashes).
     *
     * @return array{indexed: int, skipped: int, stale_removed: int, total_chunks: int}
     */
    public function reindex(int $employeeId): array
    {
        $sources = $this->catalog->scan($employeeId);
        $store = MemoryIndexStore::forAgent($employeeId);
        $store->ensureSchema();

        $currentPaths = array_map(
            fn (MemorySourceEntry $s): string => $s->relativePath,
            $sources,
        );

        $store->beginTransaction();

        try {
            $staleRemoved = $store->removeStaleEntries($currentPaths);

            foreach ($sources as $source) {
                $this->indexSource($source, $store);
            }

            $store->setMeta('last_indexed_at', (string) time());
            $store->commit();
        } catch (\Throwable $e) {
            $store->rollBack();

            throw $e;
        }

        return [
            'indexed' => count($sources),
            'skipped' => 0,
            'stale_removed' => $staleRemoved,
            'total_chunks' => $store->chunkCount(),
        ];
    }

    /**
     * Index a single memory source file.
     */
    private function indexSource(MemorySourceEntry $source, MemoryIndexStore $store): void
    {
        $content = @file_get_contents($source->path);

        if ($content === false || trim($content) === '') {
            return;
        }

        $chunks = $this->chunker->chunk($content, $source->relativePath, $source->contentHash);

        $store->deleteChunksForSource($source->relativePath);
        $store->insertChunks($chunks);

        $store->upsertManifestEntry(new MemoryIndexManifestEntry(
            relativePath: $source->relativePath,
            contentHash: $source->contentHash,
            chunkCount: count($chunks),
            indexedAt: time(),
        ));
    }
}
