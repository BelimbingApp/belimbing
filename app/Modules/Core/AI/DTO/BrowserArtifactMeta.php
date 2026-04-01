<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

use App\Modules\Core\AI\Enums\BrowserArtifactType;

/**
 * Metadata for a durable browser artifact (screenshot, snapshot, PDF, etc.).
 *
 * Stored alongside session records. The actual content is on disk;
 * this DTO carries the metadata needed to locate and describe it.
 */
final readonly class BrowserArtifactMeta
{
    public function __construct(
        public string $artifactId,
        public string $sessionId,
        public BrowserArtifactType $type,
        public string $storagePath,
        public string $mimeType,
        public int $sizeBytes,
        public ?string $relatedUrl,
        public ?string $relatedTabId,
        public string $createdAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            artifactId: $data['artifact_id'],
            sessionId: $data['session_id'],
            type: BrowserArtifactType::from($data['type']),
            storagePath: $data['storage_path'],
            mimeType: $data['mime_type'],
            sizeBytes: $data['size_bytes'],
            relatedUrl: $data['related_url'] ?? null,
            relatedTabId: $data['related_tab_id'] ?? null,
            createdAt: $data['created_at'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'artifact_id' => $this->artifactId,
            'session_id' => $this->sessionId,
            'type' => $this->type->value,
            'storage_path' => $this->storagePath,
            'mime_type' => $this->mimeType,
            'size_bytes' => $this->sizeBytes,
            'related_url' => $this->relatedUrl,
            'related_tab_id' => $this->relatedTabId,
            'created_at' => $this->createdAt,
        ];
    }
}
