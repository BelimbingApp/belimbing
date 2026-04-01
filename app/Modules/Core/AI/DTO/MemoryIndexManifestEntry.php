<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

/**
 * Per-source indexing state within the memory index manifest.
 *
 * Tracks the content hash, chunk count, and timestamps for a single
 * memory source file. The indexer compares current file hashes against
 * manifest entries to decide what needs re-indexing.
 */
final readonly class MemoryIndexManifestEntry
{
    /**
     * @param  string  $relativePath  Source file relative path
     * @param  string  $contentHash  SHA-256 at last index time
     * @param  int  $chunkCount  Number of chunks generated
     * @param  int  $indexedAt  Unix timestamp of last successful index
     */
    public function __construct(
        public string $relativePath,
        public string $contentHash,
        public int $chunkCount,
        public int $indexedAt,
    ) {}

    /**
     * @return array{relative_path: string, content_hash: string, chunk_count: int, indexed_at: int}
     */
    public function toArray(): array
    {
        return [
            'relative_path' => $this->relativePath,
            'content_hash' => $this->contentHash,
            'chunk_count' => $this->chunkCount,
            'indexed_at' => $this->indexedAt,
        ];
    }
}
