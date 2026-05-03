<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Services\Backup;

/**
 * Sidecar manifest written next to every backup artifact.
 *
 * Carries operational facts (driver, encryption mode, hash, size, timestamps)
 * but never key material, passphrases, presigned URLs, or row content.
 */
final readonly class Manifest
{
    public function __construct(
        public string $backupId,
        public string $driver,
        public string $encryptionMode,
        public string $sourceLabel,
        public string $engineVersion,
        public string $appEnvironment,
        public string $artifactPath,
        public int $sizeBytes,
        public string $sha256,
        public string $startedAt,
        public string $finishedAt,
        public string $trigger,
        public string $status,
        public ?string $errorMessage = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'backup_id' => $this->backupId,
            'driver' => $this->driver,
            'encryption_mode' => $this->encryptionMode,
            'source' => $this->sourceLabel,
            'engine_version' => $this->engineVersion,
            'app_environment' => $this->appEnvironment,
            'artifact_path' => $this->artifactPath,
            'size_bytes' => $this->sizeBytes,
            'sha256' => $this->sha256,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'trigger' => $this->trigger,
            'status' => $this->status,
            'error' => $this->errorMessage,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            backupId: (string) ($data['backup_id'] ?? ''),
            driver: (string) ($data['driver'] ?? ''),
            encryptionMode: (string) ($data['encryption_mode'] ?? ''),
            sourceLabel: (string) ($data['source'] ?? ''),
            engineVersion: (string) ($data['engine_version'] ?? ''),
            appEnvironment: (string) ($data['app_environment'] ?? ''),
            artifactPath: (string) ($data['artifact_path'] ?? ''),
            sizeBytes: (int) ($data['size_bytes'] ?? 0),
            sha256: (string) ($data['sha256'] ?? ''),
            startedAt: (string) ($data['started_at'] ?? ''),
            finishedAt: (string) ($data['finished_at'] ?? ''),
            trigger: (string) ($data['trigger'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            errorMessage: isset($data['error']) ? (string) $data['error'] : null,
        );
    }
}
