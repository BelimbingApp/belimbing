<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

/**
 * A single chunk produced from a memory source file.
 *
 * Chunks are the indexing unit: each becomes one row in the index.
 * The fingerprint allows stale detection without re-reading content.
 */
final readonly class MemoryChunk
{
    /**
     * @param  string  $sourceRelativePath  Source file relative path within workspace
     * @param  string  $sourceHash  SHA-256 of the full source file at index time
     * @param  string  $heading  Section heading or filename if no heading
     * @param  string  $content  Chunk text content
     * @param  string  $fingerprint  SHA-256 of the chunk content (for stale detection)
     * @param  int  $order  Position within the source file (0-based)
     */
    public function __construct(
        public string $sourceRelativePath,
        public string $sourceHash,
        public string $heading,
        public string $content,
        public string $fingerprint,
        public int $order,
    ) {}

    /**
     * Content size in bytes.
     */
    public function size(): int
    {
        return strlen($this->content);
    }

    /**
     * @return array{source: string, heading: string, fingerprint: string, order: int, size: int}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->sourceRelativePath,
            'heading' => $this->heading,
            'fingerprint' => $this->fingerprint,
            'order' => $this->order,
            'size' => $this->size(),
        ];
    }
}
