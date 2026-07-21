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
    ) {}

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
        ];
    }
}
