<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

/**
 * Health and freshness report for an agent's memory index.
 *
 * Returned by MemoryHealthService to surface status to operators,
 * tools, and admin UIs.
 */
final readonly class MemoryHealthReport
{
    /**
     * @param  int  $employeeId  Agent employee ID
     * @param  bool  $indexed  Whether an index exists at all
     * @param  int  $sourceCount  Number of discovered memory sources
     * @param  int  $chunkCount  Total chunks in the index
     * @param  int  $staleSourceCount  Sources whose content hash differs from manifest
     * @param  int|null  $lastIndexedAt  Unix timestamp of most recent index run
     * @param  int|null  $lastCompactedAt  Unix timestamp of most recent compaction
     * @param  bool  $embeddingsAvailable  Whether vector embeddings are configured
     */
    public function __construct(
        public int $employeeId,
        public bool $indexed,
        public int $sourceCount,
        public int $chunkCount,
        public int $staleSourceCount,
        public ?int $lastIndexedAt,
        public ?int $lastCompactedAt,
        public bool $embeddingsAvailable,
    ) {}

    /**
     * @return array{employee_id: int, indexed: bool, source_count: int, chunk_count: int, stale_source_count: int, last_indexed_at: int|null, last_compacted_at: int|null, embeddings_available: bool}
     */
    public function toArray(): array
    {
        return [
            'employee_id' => $this->employeeId,
            'indexed' => $this->indexed,
            'source_count' => $this->sourceCount,
            'chunk_count' => $this->chunkCount,
            'stale_source_count' => $this->staleSourceCount,
            'last_indexed_at' => $this->lastIndexedAt,
            'last_compacted_at' => $this->lastCompactedAt,
            'embeddings_available' => $this->embeddingsAvailable,
        ];
    }
}
