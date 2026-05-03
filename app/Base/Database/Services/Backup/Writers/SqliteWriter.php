<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Services\Backup\Writers;

use App\Base\Database\Exceptions\BackupException;
use Illuminate\Database\Connection;
use PDO;
use PDOException;

/**
 * Backup writer for SQLite connections.
 *
 * Uses `VACUUM INTO 'path'` to produce a consistent snapshot without locking
 * out concurrent writers. Restore is a file copy of the (decrypted) artifact
 * into the target path.
 */
final class SqliteWriter implements BackupWriter
{
    public function __construct(private readonly Connection $connection) {}

    public function driver(): string
    {
        return 'sqlite';
    }

    public function sourceLabel(): string
    {
        $database = $this->connection->getDatabaseName();

        return $database === ':memory:' ? 'sqlite::memory:' : 'sqlite:'.basename($database);
    }

    public function engineVersion(): string
    {
        try {
            $row = $this->connection->selectOne('select sqlite_version() as v');

            return is_object($row) && isset($row->v) ? (string) $row->v : '';
        } catch (\Throwable) {
            return '';
        }
    }

    public function ensureToolingAvailable(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            throw BackupException::toolingMissing('pdo_sqlite', 'PHP pdo_sqlite extension is required for SQLite backups');
        }

        $database = $this->connection->getDatabaseName();
        if ($database === ':memory:') {
            throw BackupException::configurationInvalid('Cannot back up an in-memory SQLite database (:memory:)');
        }

        if (! is_file($database)) {
            throw BackupException::configurationInvalid("SQLite database file not found at {$database}");
        }
    }

    public function dump(string $destinationPath): void
    {
        $this->ensureToolingAvailable();

        if (file_exists($destinationPath)) {
            // VACUUM INTO refuses to overwrite; clear stale temp.
            @unlink($destinationPath);
        }

        // Quote single quotes by doubling, per SQLite literal rules.
        $quoted = str_replace("'", "''", $destinationPath);

        try {
            $this->connection->statement("VACUUM INTO '{$quoted}'");
        } catch (PDOException $e) {
            throw BackupException::dumpFailed($e->getMessage(), $e);
        } catch (\Throwable $e) {
            throw BackupException::dumpFailed($e->getMessage(), $e);
        }

        if (! is_file($destinationPath)) {
            throw BackupException::dumpFailed("Snapshot was not created at {$destinationPath}");
        }

        @chmod($destinationPath, 0600);
    }

    public function restore(string $sourcePath, string $target): void
    {
        if (! is_file($sourcePath)) {
            throw BackupException::restoreFailed("Decrypted dump missing: {$sourcePath}");
        }

        if ($target === '') {
            throw BackupException::restoreFailed('SQLite restore target path is empty');
        }

        if (file_exists($target)) {
            throw BackupException::restoreFailed("Target file already exists: {$target}");
        }

        $directory = dirname($target);
        if (! is_dir($directory)) {
            if (! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw BackupException::restoreFailed("Cannot create target directory: {$directory}");
            }
        }

        if (! @copy($sourcePath, $target)) {
            throw BackupException::restoreFailed("Failed to write target file: {$target}");
        }

        @chmod($target, 0600);

        // Open the restored file to verify it parses as a SQLite database.
        try {
            $pdo = new PDO('sqlite:'.$target);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->query('select 1 from sqlite_master limit 1')?->closeCursor();
        } catch (PDOException $e) {
            @unlink($target);
            throw BackupException::restoreFailed('Restored file is not a valid SQLite database: '.$e->getMessage(), $e);
        }
    }

    public function isCurrentDatabase(string $target): bool
    {
        $current = $this->connection->getDatabaseName();
        if ($current === ':memory:') {
            return false;
        }

        $resolvedCurrent = realpath($current);
        $resolvedTarget = realpath($target);

        if ($resolvedCurrent !== false && $resolvedTarget !== false) {
            return $resolvedCurrent === $resolvedTarget;
        }

        return rtrim((string) $current, '/') === rtrim($target, '/');
    }
}
