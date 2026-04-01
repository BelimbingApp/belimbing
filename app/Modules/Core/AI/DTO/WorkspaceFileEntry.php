<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

use App\Modules\Core\AI\Enums\WorkspaceFileSlot;

/**
 * A single resolved file entry within an agent workspace manifest.
 */
final readonly class WorkspaceFileEntry
{
    public function __construct(
        public WorkspaceFileSlot $slot,
        public ?string $path,
        public string $source,
        public bool $exists,
        public ?int $size,
        public ?int $modifiedAt,
    ) {}

    /**
     * Create an entry for a file that exists.
     *
     * @param  string  $source  'workspace' or 'framework'
     */
    public static function found(WorkspaceFileSlot $slot, string $path, string $source): self
    {
        $stat = stat($path);

        return new self(
            slot: $slot,
            path: $path,
            source: $source,
            exists: true,
            size: $stat !== false ? $stat['size'] : null,
            modifiedAt: $stat !== false ? $stat['mtime'] : null,
        );
    }

    /**
     * Create an entry for a file that is absent.
     */
    public static function missing(WorkspaceFileSlot $slot): self
    {
        return new self(
            slot: $slot,
            path: null,
            source: 'none',
            exists: false,
            size: null,
            modifiedAt: null,
        );
    }

    /**
     * Diagnostic array representation.
     *
     * @return array{slot: string, path: string|null, source: string, exists: bool, size: int|null, modified_at: int|null}
     */
    public function toArray(): array
    {
        return [
            'slot' => $this->slot->value,
            'path' => $this->path,
            'source' => $this->source,
            'exists' => $this->exists,
            'size' => $this->size,
            'modified_at' => $this->modifiedAt,
        ];
    }
}
