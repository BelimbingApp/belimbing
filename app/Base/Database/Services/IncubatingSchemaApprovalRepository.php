<?php

namespace App\Base\Database\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes instance-local break-glass approvals for pending incubating
 * migrations. The file lives under storage so approvals never travel with git.
 */
final class IncubatingSchemaApprovalRepository
{
    private const DEFAULT_RELATIVE_PATH = 'app/.devops/incubating-schema-approvals.json';

    /**
     * @return array{environment: string, connection: string, driver: string, database: string}
     */
    public function currentDatabaseContext(?string $connectionName = null): array
    {
        $connectionName = $connectionName ?: (string) config('database.default');
        $connection = DB::connection($connectionName);
        $driver = $connection->getDriverName();
        $database = $this->normalizeDatabaseName($driver, (string) $connection->getDatabaseName());

        return [
            'environment' => app()->environment(),
            'connection' => $connectionName,
            'driver' => $driver,
            'database' => $database,
        ];
    }

    private function normalizeDatabaseName(string $driver, string $database): string
    {
        if ($driver !== 'sqlite' || $database === ':memory:' || $database === '') {
            return $database;
        }

        $realPath = realpath($database);

        return $realPath === false ? $database : $realPath;
    }

    public function path(): string
    {
        $override = getenv('BLB_INCUBATING_SCHEMA_APPROVALS');

        return is_string($override) && $override !== ''
            ? $override
            : storage_path(self::DEFAULT_RELATIVE_PATH);
    }

    /**
     * @param  array{migration_name: string, relative_path: string, sha256: string}  $finding
     * @return array<string, mixed>|null
     */
    public function approvalFor(array $finding, ?string $connectionName = null): ?array
    {
        foreach ($this->readApprovals() as $approval) {
            if ($this->matches($approval, $finding, $connectionName)) {
                return $approval;
            }
        }

        return null;
    }

    /**
     * @param  array{migration_name: string, relative_path: string, sha256: string}  $finding
     * @return array<string, mixed>
     */
    public function add(array $finding, string $backup, string $reason, int $expiresHours, bool $replace, ?string $connectionName = null): array
    {
        $payload = $this->read();
        $approvals = $payload['approvals'];
        $context = $this->currentDatabaseContext($connectionName);
        $now = now()->utc();
        $expiresAt = $now->copy()->addHours(max(1, $expiresHours));
        $approval = [
            'migration' => $finding['migration_name'],
            'path' => $finding['relative_path'],
            'sha256' => $finding['sha256'],
            'environment' => $context['environment'],
            'connection' => $context['connection'],
            'driver' => $context['driver'],
            'database' => $context['database'],
            'expires_at' => $expiresAt->toIso8601String(),
            'backup' => $backup,
            'reason' => $reason,
            'one_time' => true,
            'approved_at' => $now->toIso8601String(),
        ];

        if ($replace) {
            $approvals = array_values(array_filter(
                $approvals,
                fn (array $existing): bool => ! $this->sameTarget($existing, $finding)
                    || ! $this->contextEquals($existing, $context),
            ));
        }

        $approvals[] = $approval;
        $payload['approvals'] = $approvals;
        $this->write($payload);

        return $approval;
    }

    /**
     * Mark matching one-time approvals as consumed after a successful migrate.
     *
     * @param  list<array{migration_name: string, relative_path: string, sha256: string}>  $findings
     */
    public function consume(array $findings, ?string $connectionName = null): void
    {
        if ($findings === [] || ! is_file($this->path())) {
            return;
        }

        $payload = $this->read();
        $changed = false;
        $consumedAt = now()->utc()->toIso8601String();

        foreach ($payload['approvals'] as &$approval) {
            if (($approval['one_time'] ?? true) !== true) {
                continue;
            }

            foreach ($findings as $finding) {
                if ($this->sameTarget($approval, $finding) && $this->contextMatches($approval, $connectionName)) {
                    $approval['consumed_at'] = $consumedAt;
                    $changed = true;

                    break;
                }
            }
        }
        unset($approval);

        if ($changed) {
            $this->write($payload);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readApprovals(): array
    {
        return $this->read()['approvals'];
    }

    /**
     * @return array{approvals: list<array<string, mixed>>}
     */
    private function read(): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return ['approvals' => []];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        $approvals = is_array($decoded) && is_array($decoded['approvals'] ?? null)
            ? $decoded['approvals']
            : [];

        return [
            'approvals' => array_values(array_filter($approvals, 'is_array')),
        ];
    }

    /**
     * @param  array{approvals: list<array<string, mixed>>}  $payload
     */
    private function write(array $payload): void
    {
        $path = $this->path();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    /**
     * @param  array<string, mixed>  $approval
     * @param  array{migration_name: string, relative_path: string, sha256: string}  $finding
     */
    private function matches(array $approval, array $finding, ?string $connectionName): bool
    {
        return $this->sameTarget($approval, $finding)
            && $this->contextMatches($approval, $connectionName)
            && $this->hasBackupEvidence($approval)
            && ! $this->isExpired($approval)
            && ! $this->isConsumed($approval);
    }

    /**
     * @param  array<string, mixed>  $approval
     */
    private function contextMatches(array $approval, ?string $connectionName): bool
    {
        return $this->contextEquals($approval, $this->currentDatabaseContext($connectionName));
    }

    /**
     * @param  array<string, mixed>  $approval
     * @param  array{environment: string, connection: string, driver: string, database: string}  $context
     */
    private function contextEquals(array $approval, array $context): bool
    {
        return ($approval['environment'] ?? null) === $context['environment']
            && ($approval['connection'] ?? null) === $context['connection']
            && ($approval['driver'] ?? null) === $context['driver']
            && ($approval['database'] ?? null) === $context['database'];
    }

    /**
     * @param  array<string, mixed>  $approval
     * @param  array{migration_name: string, relative_path: string, sha256: string}  $finding
     */
    private function sameTarget(array $approval, array $finding): bool
    {
        return ($approval['migration'] ?? null) === $finding['migration_name']
            && ($approval['path'] ?? null) === $finding['relative_path']
            && ($approval['sha256'] ?? null) === $finding['sha256'];
    }

    /**
     * @param  array<string, mixed>  $approval
     */
    private function hasBackupEvidence(array $approval): bool
    {
        return is_string($approval['backup'] ?? null) && trim($approval['backup']) !== '';
    }

    /**
     * @param  array<string, mixed>  $approval
     */
    private function isConsumed(array $approval): bool
    {
        return ($approval['one_time'] ?? true) === true
            && is_string($approval['consumed_at'] ?? null)
            && $approval['consumed_at'] !== '';
    }

    /**
     * @param  array<string, mixed>  $approval
     */
    private function isExpired(array $approval): bool
    {
        if (! is_string($approval['expires_at'] ?? null) || $approval['expires_at'] === '') {
            return true;
        }

        try {
            return Carbon::parse($approval['expires_at'])->isPast();
        } catch (\Throwable) {
            return true;
        }
    }
}
