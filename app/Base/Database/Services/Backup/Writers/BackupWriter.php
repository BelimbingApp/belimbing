<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Services\Backup\Writers;

/**
 * Driver-specific backup pipeline.
 *
 * Each implementation knows how to produce a consistent plaintext dump of the
 * configured connection, and how to restore a plaintext dump into a target
 * that is not the current application database.
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

    /**
     * Restore a plaintext dump from $sourcePath into the target.
     *
     * For Postgres, $target is a database name (must already exist or be
     * creatable by the connection's role). For SQLite, $target is a file path.
     */
    public function restore(string $sourcePath, string $target): void;

    /**
     * Determine whether the given target refers to the currently configured
     * application database. Used by the restore guard.
     */
    public function isCurrentDatabase(string $target): bool;
}
