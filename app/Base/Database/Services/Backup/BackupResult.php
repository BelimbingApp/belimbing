<?php
namespace App\Base\Database\Services\Backup;

/**
 * Outcome of a single blb:db:backup invocation.
 */
final readonly class BackupResult
{
    public function __construct(
        public ?Manifest $manifest,
        public ?string $artifactPath,
        public ?string $manifestPath,
        public bool $dryRun,
        public string $driver,
        public string $encryptionMode,
    ) {}
}
