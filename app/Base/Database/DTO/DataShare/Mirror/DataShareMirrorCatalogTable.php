<?php

namespace App\Base\Database\DTO\DataShare\Mirror;

final readonly class DataShareMirrorCatalogTable
{
    /** @param list<DataShareMirrorBlocker> $blockers */
    public function __construct(
        public string $table,
        public ?string $moduleName,
        public ?string $modulePath,
        public ?string $migrationFile,
        public bool $localExists,
        public bool $mirrorExists,
        public ?string $localKind,
        public ?string $mirrorKind,
        public bool $supported,
        public array $blockers = [],
        // Exact live counts captured while loading the current endpoint catalog.
        public ?int $localRows = null,
        public ?int $remoteRows = null,
        // Timestamp/freshness from the last durable transfer or explicit baseline.
        public ?string $observedAt = null,
        // False when the remote endpoint could not be reached: the Local catalog
        // still renders and stays selectable, but remote columns are unavailable
        // rather than falsely "missing".
        public bool $remoteAvailable = true,
        // clean | changed | unknown. SQLite and unproven drivers are always unknown.
        public string $freshness = 'unknown',
    ) {}

    /**
     * Return a copy carrying the latest persisted observation and freshness.
     */
    public function withObservation(?string $observedAt, string $freshness = 'unknown'): self
    {
        return new self(
            $this->table,
            $this->moduleName,
            $this->modulePath,
            $this->migrationFile,
            $this->localExists,
            $this->mirrorExists,
            $this->localKind,
            $this->mirrorKind,
            $this->supported,
            $this->blockers,
            $this->localRows,
            $this->remoteRows,
            $observedAt,
            $this->remoteAvailable,
            $freshness,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'module_name' => $this->moduleName,
            'module_path' => $this->modulePath,
            'migration_file' => $this->migrationFile,
            'local_exists' => $this->localExists,
            'mirror_exists' => $this->mirrorExists,
            'local_kind' => $this->localKind,
            'mirror_kind' => $this->mirrorKind,
            'supported' => $this->supported,
            'blockers' => array_map(fn (DataShareMirrorBlocker $blocker): array => $blocker->toArray(), $this->blockers),
            'local_rows' => $this->localRows,
            'remote_rows' => $this->remoteRows,
            'observed_at' => $this->observedAt,
            'remote_available' => $this->remoteAvailable,
            'freshness' => $this->freshness,
        ];
    }
}
