<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

use App\Modules\Core\AI\Enums\MemoryFileType;

/**
 * A single discovered memory source file for an agent.
 *
 * Represents one canonical or daily memory file with enough
 * metadata for the indexer to decide whether re-indexing is needed.
 */
final readonly class MemorySourceEntry
{
    /**
     * @param  string  $path  Absolute path to the memory file
     * @param  string  $relativePath  Path relative to the agent workspace root
     * @param  MemoryFileType  $type  Trust/retention classification
     * @param  string  $contentHash  SHA-256 hash of file contents
     * @param  int  $size  File size in bytes
     * @param  int  $modifiedAt  Unix timestamp of last modification
     */
    public function __construct(
        public string $path,
        public string $relativePath,
        public MemoryFileType $type,
        public string $contentHash,
        public int $size,
        public int $modifiedAt,
    ) {}

    /**
     * Build an entry from a file on disk.
     *
     * @param  string  $absolutePath  Full filesystem path
     * @param  string  $relativePath  Path relative to workspace root
     * @param  MemoryFileType  $type  Classification of this memory source
     */
    public static function fromFile(string $absolutePath, string $relativePath, MemoryFileType $type): self
    {
        $content = @file_get_contents($absolutePath);
        $stat = @stat($absolutePath);

        return new self(
            path: $absolutePath,
            relativePath: $relativePath,
            type: $type,
            contentHash: $content !== false ? hash('sha256', $content) : '',
            size: $stat !== false ? $stat['size'] : 0,
            modifiedAt: $stat !== false ? $stat['mtime'] : 0,
        );
    }

    /**
     * @return array{path: string, relative_path: string, type: string, content_hash: string, size: int, modified_at: int}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'relative_path' => $this->relativePath,
            'type' => $this->type->value,
            'content_hash' => $this->contentHash,
            'size' => $this->size,
            'modified_at' => $this->modifiedAt,
        ];
    }
}
