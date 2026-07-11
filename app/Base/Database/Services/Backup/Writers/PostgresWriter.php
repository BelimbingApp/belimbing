<?php

namespace App\Base\Database\Services\Backup\Writers;

use App\Base\Database\Exceptions\BackupException;
use Illuminate\Database\Connection;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Backup writer for PostgreSQL connections.
 *
 * Uses `pg_dump --format=custom` for a consistent online snapshot. Credentials
 * are passed via PGPASSWORD env so they never appear on the command line.
 */
final class PostgresWriter implements BackupWriter
{
    private const DUMP_TIMEOUT = 3600;

    public function __construct(private readonly Connection $connection) {}

    public function driver(): string
    {
        return 'pgsql';
    }

    public function sourceLabel(): string
    {
        $database = (string) $this->connection->getDatabaseName();
        $config = $this->connection->getConfig();
        $host = (string) ($config['host'] ?? 'localhost');

        return "pgsql://{$host}/{$database}";
    }

    public function engineVersion(): string
    {
        try {
            $version = $this->connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);

            return is_string($version) ? $version : '';
        } catch (\Throwable) {
            return '';
        }
    }

    public function ensureToolingAvailable(): void
    {
        $finder = new ExecutableFinder;

        if ($finder->find('pg_dump') === null) {
            throw BackupException::toolingMissing('pg_dump', 'install postgresql client tools on the host');
        }
    }

    public function dump(string $destinationPath): void
    {
        $finder = new ExecutableFinder;

        $pgDump = $finder->find('pg_dump');
        if ($pgDump === null) {
            throw BackupException::toolingMissing('pg_dump', 'install postgresql client tools on the host');
        }

        $config = $this->connection->getConfig();

        $command = [
            $pgDump,
            '--format=custom',
            '--no-owner',
            '--no-privileges',
            '--file='.$destinationPath,
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.((string) ($config['port'] ?? '5432')),
            '--username='.($config['username'] ?? ''),
            '--dbname='.($config['database'] ?? ''),
        ];

        $env = ['PGPASSWORD' => (string) ($config['password'] ?? '')];

        $this->runProcess($command, $env, self::DUMP_TIMEOUT, 'pg_dump');

        if (! is_file($destinationPath)) {
            throw BackupException::dumpFailed("pg_dump completed but no file was created at {$destinationPath}");
        }

        @chmod($destinationPath, 0600);
    }

    /**
     * @param  array<int, string>  $command
     * @param  array<string, string>  $env
     */
    private function runProcess(array $command, array $env, int $timeout, string $label): void
    {
        $process = new Process($command, null, $env, null, $timeout);

        try {
            $process->run();
        } catch (ProcessRuntimeException $e) {
            throw BackupException::dumpFailed("{$label} could not be started: ".$e->getMessage(), $e);
        }

        if (! $process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());
            if ($stderr === '') {
                $stderr = trim($process->getOutput());
            }

            throw BackupException::dumpFailed(
                "{$label} exited with status {$process->getExitCode()}: ".$stderr,
                new ProcessFailedException($process),
            );
        }
    }
}
