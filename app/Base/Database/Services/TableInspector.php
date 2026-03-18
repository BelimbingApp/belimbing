<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Services;

use App\Base\Database\Models\TableRegistry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only inspector for any registered database table.
 *
 * Provides column metadata and paginated row access via Laravel's
 * Schema Builder and Query Builder — fully DB-agnostic.
 */
class TableInspector
{
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
        return Schema::getColumns($table);
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
        return TableRegistry::query()->where('table_name', $table)->exists();
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
        $registeredTables = TableRegistry::query()->pluck('table_name')->all();

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
            ->orderBy('module_name')
            ->orderBy('table_name')
            ->get(['table_name', 'module_name'])
            ->groupBy(fn ($row) => $row->module_name ?? __('Laravel'))
            ->map(fn ($group) => $group->map(fn ($row) => ['table_name' => $row->table_name])->values()->all())
            ->all();
    }

    /**
     * Check if a column exists in a table.
     */
    private function columnExists(string $table, string $column): bool
    {
        return Schema::hasColumn($table, $column);
    }
}
