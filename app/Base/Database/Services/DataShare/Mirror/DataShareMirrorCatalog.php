<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorBlocker;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorCatalogTable;
use App\Base\Database\Models\TableRegistry;
use Illuminate\Database\Connection;

class DataShareMirrorCatalog
{
    /** @var list<string> */
    private const FIXED_PROTECTED_TABLES = [
        'base_settings',
        'base_database_data_share_events',
        'base_database_data_share_plans',
        'base_database_data_share_plan_actions',
        'base_database_data_share_receipts',
        'base_database_data_share_transfer_offers',
    ];

    public function __construct(private readonly DataShareMirrorConnectionManager $connections) {}

    /** @return list<DataShareMirrorCatalogTable> */
    public function catalog(): array
    {
        $this->connections->assertAvailable();
        $local = $this->snapshot($this->connections->local());
        $mirror = $this->snapshot($this->connections->mirror());
        $names = array_values(array_unique(array_merge(
            array_keys($local['registry']),
            array_keys($mirror['registry']),
        )));
        sort($names, SORT_STRING);
        $tables = [];

        foreach ($names as $name) {
            $localRegistry = $local['registry'][$name] ?? null;
            $mirrorRegistry = $mirror['registry'][$name] ?? null;
            $localRelation = $local['relations'][$name] ?? null;
            $mirrorRelation = $mirror['relations'][$name] ?? null;
            $owner = $localRegistry ?? $mirrorRegistry;
            $blockers = [];

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_$]{0,62}$/', $name) !== 1) {
                $blockers[] = new DataShareMirrorBlocker(
                    'invalid_identifier',
                    __(':table does not use a mirror-safe table name.', ['table' => $name]),
                );
            }

            if ($this->isProtected($name)) {
                $blockers[] = new DataShareMirrorBlocker(
                    'protected_table',
                    __(':table is Base infrastructure or runtime state and cannot be mirrored.', ['table' => $name]),
                );
            }

            if (($localRelation !== null && $localRegistry === null) || ($mirrorRelation !== null && $mirrorRegistry === null)) {
                $blockers[] = new DataShareMirrorBlocker(
                    'owner_missing',
                    __(':table is not registered to a module on every endpoint where it exists.', ['table' => $name]),
                );
            }

            if ($localRegistry !== null && $mirrorRegistry !== null && ! $this->sameOwner($localRegistry, $mirrorRegistry)) {
                $blockers[] = new DataShareMirrorBlocker(
                    'owner_mismatch',
                    __(':table is registered to different module owners on Local and :provider.', [
                        'table' => $name,
                        'provider' => $this->connections->provider()->label(),
                    ]),
                );
            }

            foreach ([[__('Local'), $localRelation], [$this->connections->provider()->connectionLabel(), $mirrorRelation]] as [$label, $relation]) {
                if ($relation !== null && $relation['kind'] !== 'table') {
                    $blockers[] = new DataShareMirrorBlocker(
                        'unsupported_relation',
                        __(':table is a :kind relation on :endpoint; only ordinary tables are supported.', [
                            'table' => $name,
                            'kind' => $relation['kind'],
                            'endpoint' => $label,
                        ]),
                    );
                }
            }

            $tables[] = new DataShareMirrorCatalogTable(
                table: $name,
                moduleName: $owner['module_name'] ?? null,
                modulePath: $owner['module_path'] ?? null,
                migrationFile: $owner['migration_file'] ?? null,
                localExists: $localRelation !== null,
                mirrorExists: $mirrorRelation !== null,
                localKind: $localRelation['kind'] ?? null,
                mirrorKind: $mirrorRelation['kind'] ?? null,
                supported: $blockers === [],
                blockers: $blockers,
            );
        }

        return $tables;
    }

    public function isMigrationAvailable(DataShareMirrorCatalogTable $table): bool
    {
        if ($table->migrationFile === null) {
            return true;
        }

        $migration = basename($table->migrationFile);
        if ($migration !== $table->migrationFile || preg_match('/^[A-Za-z0-9_.-]+\.php$/', $migration) !== 1) {
            return false;
        }

        $paths = [database_path('migrations/'.$migration)];

        if ($table->modulePath !== null
            && preg_match('#^(?:app/(?:Base|Modules)/[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)?|extensions/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)$#', $table->modulePath) === 1) {
            $paths[] = base_path($table->modulePath.'/Database/Migrations/'.$migration);
        }

        foreach ($paths as $path) {
            if (is_file($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *   registry: array<string, array{module_name: string|null, module_path: string|null, migration_file: string|null}>,
     *   relations: array<string, array{kind: string}>
     * }
     */
    private function snapshot(Connection $connection): array
    {
        $registryRows = $connection->table('base_database_tables')
            ->orderBy('table_name')
            ->get(['table_name', 'module_name', 'module_path', 'migration_file']);
        $registry = [];

        foreach ($registryRows as $row) {
            $registry[(string) $row->table_name] = [
                'module_name' => is_string($row->module_name ?? null) ? $row->module_name : null,
                'module_path' => is_string($row->module_path ?? null) ? $row->module_path : null,
                'migration_file' => is_string($row->migration_file ?? null) ? $row->migration_file : null,
            ];
        }

        $relationRows = $connection->getDriverName() === 'sqlite'
            ? $connection->select(<<<'SQL'
                SELECT name AS table_name,
                       CASE type WHEN 'table' THEN 'r' ELSE 'v' END AS relkind,
                       0 AS relispartition,
                       0 AS inherits
                FROM sqlite_master
                WHERE type IN ('table', 'view')
                  AND name NOT LIKE 'sqlite_%'
                ORDER BY name
                SQL)
            : $connection->select(<<<'SQL'
            SELECT c.relname AS table_name, c.relkind, c.relispartition,
                   EXISTS (
                       SELECT 1 FROM pg_inherits i
                       WHERE i.inhrelid = c.oid OR i.inhparent = c.oid
                   ) AS inherits
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'
              AND c.relkind IN ('r', 'p', 'v', 'm', 'f')
            ORDER BY c.relname
            SQL);
        $relations = [];

        foreach ($relationRows as $row) {
            $relations[(string) $row->table_name] = [
                'kind' => $this->relationKind(
                    (string) $row->relkind,
                    filter_var($row->relispartition, FILTER_VALIDATE_BOOL),
                    filter_var($row->inherits, FILTER_VALIDATE_BOOL),
                ),
            ];
        }

        return ['registry' => $registry, 'relations' => $relations];
    }

    /** @param array<string, string|null> $left @param array<string, string|null> $right */
    private function sameOwner(array $left, array $right): bool
    {
        return ($left['module_path'] ?? null) === ($right['module_path'] ?? null)
            && ($left['module_name'] ?? null) === ($right['module_name'] ?? null)
            && ($left['migration_file'] ?? null) === ($right['migration_file'] ?? null);
    }

    private function relationKind(string $relkind, bool $isPartition, bool $inherits): string
    {
        if ($isPartition || $inherits) {
            return 'partition';
        }

        return match ($relkind) {
            'r' => 'table',
            'p' => 'partitioned_table',
            'v' => 'view',
            'm' => 'materialized_view',
            'f' => 'foreign_table',
            default => 'unsupported',
        };
    }

    private function isProtected(string $table): bool
    {
        $configured = array_filter([
            config('session.table', 'sessions'),
            config('queue.connections.database.table', 'jobs'),
            config('queue.failed.table', 'failed_jobs'),
            config('cache.stores.database.table', 'cache'),
            config('cache.stores.database.lock_table', 'cache_locks'),
            'job_batches',
        ], 'is_string');

        return in_array($table, array_unique(array_merge(
            TableRegistry::INFRASTRUCTURE_TABLES,
            self::FIXED_PROTECTED_TABLES,
            $configured,
        )), true);
    }
}
