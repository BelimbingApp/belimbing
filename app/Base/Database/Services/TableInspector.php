<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Services;

use App\Base\Database\Models\TableRegistry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Read-only inspector for any registered database table.
 *
 * Provides column metadata and paginated row access via Laravel's
 * Schema Builder and Query Builder — fully DB-agnostic.
 */
class TableInspector
{
    private const ORPHANED_REGISTRY_SESSION_KEY = 'database_tables.orphaned_registry_entries';

    /**
     * Column types considered searchable (LIKE-compatible).
     *
     * @var list<string>
     */
    private const SEARCHABLE_TYPE_PATTERNS = [
        'char',
        'text',
        'varchar',
        'string',
    ];

    /**
     * Get column metadata for a table.
     *
     * @return list<array{name: string, type_name: string, type: string, nullable: bool, default: mixed, auto_increment: bool}>
     */
    public function columns(string $table): array
    {
        $this->guardRegisteredRelationExists($table);

        return Schema::getColumns($table);
    }

    /**
     * Get index metadata for a table.
     *
     * @return list<array{name: string, columns: list<string>, type: string, unique: bool, primary: bool}>
     */
    public function indexes(string $table): array
    {
        $this->guardRegisteredRelationExists($table);

        return Schema::getIndexes($table);
    }

    /**
     * Get paginated rows with optional search and sort.
     *
     * @param  string  $table  Table name
     * @param  string|null  $search  Search term (LIKE across string columns)
     * @param  string|null  $sortColumn  Column to sort by (must exist in table)
     * @param  string  $sortDirection  'asc' or 'desc'
     * @param  int  $perPage  Rows per page
     */
    public function rows(
        string $table,
        ?string $search,
        ?string $sortColumn,
        string $sortDirection = 'asc',
        int $perPage = 25,
    ): LengthAwarePaginator {
        $this->guardRegisteredRelationExists($table);

        $query = DB::table($table);

        if ($search !== null && $search !== '') {
            $this->applySearch($query, $table, $search);
        }

        if ($sortColumn !== null && $sortColumn !== '' && $this->columnExists($table, $sortColumn)) {
            $query->orderBy($sortColumn, $sortDirection === 'desc' ? 'desc' : 'asc');
        }

        return $query->paginate($perPage);
    }

    /**
     * Apply a LIKE search across all searchable columns.
     */
    private function applySearch(Builder $query, string $table, string $search): void
    {
        $searchable = $this->searchableColumns($table);

        if ($searchable === []) {
            return;
        }

        $query->where(function ($q) use ($searchable, $search): void {
            foreach ($searchable as $index => $column) {
                $index === 0
                    ? $q->where($column, 'like', '%'.$search.'%')
                    : $q->orWhere($column, 'like', '%'.$search.'%');
            }
        });
    }

    /**
     * Get total row count for a table.
     */
    public function rowCount(string $table): int
    {
        $this->guardRegisteredRelationExists($table);

        return DB::table($table)->count();
    }

    /**
     * Get column names that are searchable (string/text types).
     *
     * @return list<string>
     */
    public function searchableColumns(string $table): array
    {
        $columns = $this->columns($table);
        $searchable = [];

        foreach ($columns as $column) {
            $typeName = strtolower($column['type_name']);

            foreach (self::SEARCHABLE_TYPE_PATTERNS as $pattern) {
                if (str_contains($typeName, $pattern)) {
                    $searchable[] = $column['name'];
                    break;
                }
            }
        }

        return $searchable;
    }

    /**
     * Check if a table is registered in the TableRegistry.
     */
    public function isRegistered(string $table): bool
    {
        if (! TableRegistry::query()->where('table_name', $table)->exists()) {
            return false;
        }

        return $this->ensureRegisteredRelationExists($table, forgetMissing: true);
    }

    /**
     * Get foreign key relationships for a table.
     *
     * Returns both outgoing (this table references others) and incoming
     * (other tables reference this one) foreign keys.
     *
     * @return array{outgoing: list<array{column: string, foreign_table: string, foreign_column: string}>, incoming: list<array{table: string, column: string, local_column: string}>}
     */
    public function foreignKeys(string $table): array
    {
        $this->guardRegisteredRelationExists($table);

        $outgoing = [];
        foreach (Schema::getForeignKeys($table) as $fk) {
            foreach ($fk['columns'] as $i => $column) {
                $outgoing[] = [
                    'column' => $column,
                    'foreign_table' => $fk['foreign_table'],
                    'foreign_column' => $fk['foreign_columns'][$i],
                ];
            }
        }

        $incoming = [];
        $registeredTables = TableRegistry::getAvailableTableNames();

        foreach ($registeredTables as $otherTable) {
            if ($otherTable === $table) {
                continue;
            }

            foreach (Schema::getForeignKeys($otherTable) as $fk) {
                if ($fk['foreign_table'] !== $table) {
                    continue;
                }

                foreach ($fk['columns'] as $i => $column) {
                    $incoming[] = [
                        'table' => $otherTable,
                        'column' => $column,
                        'local_column' => $fk['foreign_columns'][$i],
                    ];
                }
            }
        }

        return ['outgoing' => $outgoing, 'incoming' => $incoming];
    }

    /**
     * Get all registered tables grouped by module name.
     *
     * @return array<string, list<array{table_name: string}>>
     */
    public function allTablesGroupedByModule(): array
    {
        return TableRegistry::query()
            ->whereIn('table_name', TableRegistry::getAvailableTableNames())
            ->orderBy('module_name')
            ->orderBy('table_name')
            ->get(['table_name', 'module_name'])
            ->groupBy(fn ($row) => $row->module_name ?? __('Laravel'))
            ->map(fn ($group) => $group->map(fn ($row) => ['table_name' => $row->table_name])->values()->all())
            ->all();
    }

    /**
     * Get migration source metadata for a registered table.
     *
     * @return array{file_name: string, relative_path: string, contents: string}|null
     */
    public function migrationSource(string $table): ?array
    {
        $registry = TableRegistry::query()
            ->where('table_name', $table)
            ->first();

        if ($registry === null || $registry->migration_file === null) {
            return null;
        }

        $absolutePath = $this->resolveMigrationPath($registry->module_path, $registry->migration_file);

        if ($absolutePath === null) {
            return null;
        }

        $contents = file_get_contents($absolutePath);

        if ($contents === false) {
            return null;
        }

        return [
            'file_name' => $registry->migration_file,
            'relative_path' => $this->relativeBasePath($absolutePath),
            'contents' => $contents,
        ];
    }

    /**
     * Consume and clear orphaned registry notices for the current session.
     *
     * @return list<string>
     */
    public function pullOrphanedRegistryNotices(): array
    {
        return session()->pull(self::ORPHANED_REGISTRY_SESSION_KEY, []);
    }

    /**
     * Reconcile the registry and return human-readable orphan cleanup notices.
     *
     * @return list<string>
     */
    public function reconcileRegistry(): array
    {
        $result = TableRegistry::reconcile();

        foreach ($result['removed'] as $table) {
            $this->recordOrphanedRegistryNotice($table);
        }

        return $this->pullOrphanedRegistryNotices();
    }

    /**
     * Check if a column exists in a table.
     */
    private function columnExists(string $table, string $column): bool
    {
        return Schema::hasColumn($table, $column);
    }

    /**
     * Ensure a registered relation exists or fail as not found.
     */
    private function guardRegisteredRelationExists(string $table): void
    {
        if ($this->ensureRegisteredRelationExists($table, forgetMissing: true)) {
            return;
        }

        throw new NotFoundHttpException(__('Database relation [:table] was not found.', ['table' => $table]));
    }

    /**
     * Ensure a registered relation still exists, pruning orphaned registry rows.
     */
    private function ensureRegisteredRelationExists(string $table, bool $forgetMissing = false): bool
    {
        if (TableRegistry::relationExists($table)) {
            return true;
        }

        if ($forgetMissing && TableRegistry::removeIfOrphaned($table)) {
            $this->recordOrphanedRegistryNotice($table);
        }

        return false;
    }

    /**
     * Record a session notice describing a pruned orphaned registry entry.
     */
    private function recordOrphanedRegistryNotice(string $table): void
    {
        $message = __('Removed orphaned registry entry for :table because the relation no longer exists.', [
            'table' => $table,
        ]);

        $messages = session()->get(self::ORPHANED_REGISTRY_SESSION_KEY, []);

        if (! in_array($message, $messages, true)) {
            $messages[] = $message;
        }

        session()->put(self::ORPHANED_REGISTRY_SESSION_KEY, $messages);
    }

    /**
     * Resolve the absolute filesystem path for a migration file.
     */
    private function resolveMigrationPath(?string $modulePath, string $migrationFile): ?string
    {
        $candidates = [];

        if ($modulePath !== null) {
            $candidates[] = base_path($modulePath.'/Database/Migrations/'.$migrationFile);
        }

        $candidates[] = database_path('migrations/'.$migrationFile);

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Convert an absolute path to a workspace-relative path.
     */
    private function relativeBasePath(string $absolutePath): string
    {
        return str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $absolutePath);
    }
}
