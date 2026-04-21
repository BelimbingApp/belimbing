<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Browser;

use App\Base\Support\File as BlbFile;
use App\Modules\Core\AI\DTO\BrowserArtifactMeta;
use App\Modules\Core\AI\Enums\BrowserArtifactType;
use App\Modules\Core\AI\Models\BrowserArtifact;
use Illuminate\Support\Str;

/**
 * Persists browser artifacts (screenshots, snapshots, PDFs) as durable outputs.
 *
 * Artifacts are stored on disk under a session-scoped directory and tracked
 * in the database for retrieval, inspection, and cleanup. The store handles
 * both binary (screenshot, PDF) and text (snapshot, evaluate result) content.
 */
class BrowserArtifactStore
{
    private const DEFAULT_ARTIFACT_DIR = 'browser-artifacts';

    /**
     * Store a browser artifact and return its metadata.
     *
     * @param  string  $sessionId  Owning browser session
     * @param  BrowserArtifactType  $type  Artifact type
     * @param  string  $content  Raw content (binary for images/pdfs, text for snapshots)
     * @param  string|null  $relatedUrl  URL the artifact was captured from
     * @param  string|null  $relatedTabId  Tab the artifact was captured from
     */
    public function store(
        string $sessionId,
        BrowserArtifactType $type,
        string $content,
        ?string $relatedUrl = null,
        ?string $relatedTabId = null,
    ): BrowserArtifactMeta {
        $artifactId = 'ba_'.Str::ulid()->toBase32();
        $extension = $this->extensionForType($type);
        $relativePath = $this->artifactRootDir()."/{$sessionId}/{$artifactId}.{$extension}";
        $absolutePath = $this->absoluteArtifactPath($relativePath);

        BlbFile::put($absolutePath, $content);

        $artifact = BrowserArtifact::query()->create([
            'id' => $artifactId,
            'browser_session_id' => $sessionId,
            'type' => $type,
            'storage_path' => $relativePath,
            'mime_type' => $type->mimeType(),
            'size_bytes' => strlen($content),
            'related_url' => $relatedUrl,
            'related_tab_id' => $relatedTabId,
        ]);

        return new BrowserArtifactMeta(
            artifactId: $artifact->id,
            sessionId: $artifact->browser_session_id,
            type: $type,
            storagePath: $artifact->storage_path,
            mimeType: $artifact->mime_type,
            sizeBytes: $artifact->size_bytes,
            relatedUrl: $artifact->related_url,
            relatedTabId: $artifact->related_tab_id,
            createdAt: $artifact->created_at->toIso8601String(),
        );
    }

    /**
     * Retrieve artifact metadata by ID.
     */
    public function find(string $artifactId): ?BrowserArtifactMeta
    {
        $artifact = BrowserArtifact::query()->find($artifactId);

        if ($artifact === null) {
            return null;
        }

        return $this->toMeta($artifact);
    }

    /**
     * List all artifacts for a browser session.
     *
     * @return list<BrowserArtifactMeta>
     */
    public function listForSession(string $sessionId): array
    {
        return BrowserArtifact::query()
            ->where('browser_session_id', $sessionId)
            ->orderBy('created_at')
            ->get()
            ->map(fn (BrowserArtifact $a): BrowserArtifactMeta => $this->toMeta($a))
            ->values()
            ->all();
    }

    /**
     * Read artifact content from disk.
     *
     * @return string|null Raw content, or null if the file is missing
     */
    public function readContent(string $artifactId): ?string
    {
        $artifact = BrowserArtifact::query()->find($artifactId);

        if ($artifact === null) {
            return null;
        }

        $path = $this->absoluteArtifactPath($artifact->storage_path);

        if (! file_exists($path)) {
            return null;
        }

        return file_get_contents($path) ?: null;
    }

    /**
     * Delete all artifacts for a session (disk + database).
     */
    public function deleteForSession(string $sessionId): int
    {
        $artifacts = BrowserArtifact::query()
            ->where('browser_session_id', $sessionId)
            ->get();

        foreach ($artifacts as $artifact) {
            $path = $this->absoluteArtifactPath($artifact->storage_path);

            if (file_exists($path)) {
                @unlink($path);
            }
        }

        // Clean up session directory if empty.
        $sessionDir = $this->sessionDirectoryPath($sessionId);

        if (is_dir($sessionDir) && count(scandir($sessionDir)) <= 2) {
            @rmdir($sessionDir);
        }

        return BrowserArtifact::query()
            ->where('browser_session_id', $sessionId)
            ->delete();
    }

    private function toMeta(BrowserArtifact $artifact): BrowserArtifactMeta
    {
        return new BrowserArtifactMeta(
            artifactId: $artifact->id,
            sessionId: $artifact->browser_session_id,
            type: $artifact->type,
            storagePath: $artifact->storage_path,
            mimeType: $artifact->mime_type,
            sizeBytes: $artifact->size_bytes,
            relatedUrl: $artifact->related_url,
            relatedTabId: $artifact->related_tab_id,
            createdAt: $artifact->created_at?->toIso8601String() ?? '',
        );
    }

    private function extensionForType(BrowserArtifactType $type): string
    {
        return match ($type) {
            BrowserArtifactType::Snapshot => 'txt',
            BrowserArtifactType::Screenshot => 'png',
            BrowserArtifactType::Pdf => 'pdf',
            BrowserArtifactType::EvaluateResult => 'json',
        };
    }

    private function artifactRootDir(): string
    {
        return (string) config('ai.tools.browser.artifact_dir', self::DEFAULT_ARTIFACT_DIR);
    }

    private function absoluteArtifactPath(string $relativePath): string
    {
        return storage_path("app/{$relativePath}");
    }

    private function sessionDirectoryPath(string $sessionId): string
    {
        return $this->absoluteArtifactPath($this->artifactRootDir()."/{$sessionId}");
    }
}
