<?php

namespace App\Base\Database\Services\Backup\Writers;

use App\Base\Database\Exceptions\BackupException;
use Illuminate\Database\Connection;
use PDOException;

/**
 * Backup writer for SQLite connections.
 *
 * Uses `VACUUM INTO 'path'` to produce a consistent snapshot without locking
 * out concurrent writers.
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
}
