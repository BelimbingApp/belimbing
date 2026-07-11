<?php

namespace App\Base\Database\Services\Backup\Writers;

/**
 * Driver-specific backup pipeline.
 *
 * Each implementation knows how to produce a consistent plaintext dump of the
 * configured connection.
 */
interface BackupWriter
{
    /**
     * Stable identifier persisted in the manifest (e.g. 'pgsql', 'sqlite').
     */
    public function driver(): string;

    /**
     * Stable, human-readable identifier for the source database — used in
     * manifest and operator output. Must not contain credentials.
     */
    public function sourceLabel(): string;

    /**
     * Engine version string for the source (best-effort), recorded in the
     * manifest. Empty string is acceptable when unavailable.
     */
    public function engineVersion(): string;

    /**
     * Verify that any required external tooling is reachable. Throws
     * BackupException::toolingMissing when the environment cannot satisfy a
     * dump or restore. Used by --dry-run.
     */
    public function ensureToolingAvailable(): void;

    /**
     * Produce a consistent plaintext dump at the given path. The destination
     * is always a fresh path managed by the caller; implementations must not
     * leak intermediate plaintext to other locations.
     */
    public function dump(string $destinationPath): void;
}
