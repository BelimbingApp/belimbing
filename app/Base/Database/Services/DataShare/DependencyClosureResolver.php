<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\Exceptions\DataShareCaptureException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves the outgoing foreign-key dependency closure for selected rows.
 *
 * Walks parents only: rows referenced by the selection, directly or
 * transitively, are required for the selection to satisfy foreign key
 * constraints on import. Dependent child rows are never pulled in.
 */
class DependencyClosureResolver
{
    public function __construct(private readonly DataShareSettings $settings) {}

    /**
     * Resolve selected rows plus every transitively referenced parent row.
     *
     * Tables are ordered deepest ancestors first so an importer inserting in
     * order never violates a foreign key constraint.
     *
     * @param  list<int|string>  $ids
     * @return list<array{table: string, depth: int, primary_key: string|null, rows: list<array<string, mixed>>}>
     */
    public function resolve(string $rootTable, string $primaryKey, array $ids): array
    {
        $ids = array_values(array_unique($ids, SORT_REGULAR));
        $maxRows = $this->settings->integer('data_share.limits.max_closure_rows', 5000, 1, 1000000);
        $maxDepth = $this->settings->integer('data_share.limits.max_closure_depth', 8, 1, 64);

        $rootRows = DB::table($rootTable)
            ->whereIn($primaryKey, $ids)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        if (count($rootRows) !== count($ids)) {
            throw DataShareCaptureException::selectionChanged();
        }

        $tables = $this->closureTables($rootTable, $rootRows, $maxRows, $maxDepth);

        return $this->orderedResult($tables);
    }

    /**
     * @param  list<array<string, mixed>>  $rootRows
     * @return array<string, array{depth: int, rows: list<array<string, mixed>>}>
     */
    private function closureTables(string $rootTable, array $rootRows, int $maxRows, int $maxDepth): array
    {
        $tables = [];
        $seen = [];
        $totalRows = 0;
        $queue = [[$rootTable, $rootRows, 0]];

        while ($queue !== []) {
            [$table, $rows, $depth] = array_shift($queue);
            $fresh = $this->freshRows($table, $rows, $seen, $totalRows, $maxRows, $maxDepth, $depth);

            $this->appendFreshRows($tables, $table, $fresh, $depth);
            $this->enqueueParentRows($queue, $table, $fresh, $depth);
        }

        return $tables;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, array<string, true>>  $seen
     * @return list<array<string, mixed>>
     */
    private function freshRows(
        string $table,
        array $rows,
        array &$seen,
        int &$totalRows,
        int $maxRows,
        int $maxDepth,
        int $depth,
    ): array {
        if ($depth > $maxDepth) {
            throw DataShareCaptureException::closureTooDeep($maxDepth);
        }

        $fresh = [];

        foreach ($rows as $row) {
            $identity = $this->rowIdentity($row);

            if (isset($seen[$table][$identity])) {
                continue;
            }

            $seen[$table][$identity] = true;
            $fresh[] = $row;

            if (++$totalRows > $maxRows) {
                throw DataShareCaptureException::closureTooLarge($maxRows);
            }
        }

        return $fresh;
    }

    /**
     * @param  array<string, array{depth: int, rows: list<array<string, mixed>>}>  $tables
     * @param  list<array<string, mixed>>  $fresh
     */
    private function appendFreshRows(array &$tables, string $table, array $fresh, int $depth): void
    {
        if (! isset($tables[$table]) && $fresh === []) {
            return;
        }

        $tables[$table] ??= ['depth' => $depth, 'rows' => []];
        $tables[$table]['depth'] = max($tables[$table]['depth'], $depth);
        $tables[$table]['rows'] = array_merge($tables[$table]['rows'], $fresh);
    }

    /**
     * @param  list<array{0: string, 1: list<array<string, mixed>>, 2: int}>  $queue
     * @param  list<array<string, mixed>>  $fresh
     */
    private function enqueueParentRows(array &$queue, string $table, array $fresh, int $depth): void
    {
        if ($fresh === []) {
            return;
        }

        foreach ($this->outgoingForeignKeys($table) as $fk) {
            $parents = $this->parentRows($fk, $fresh);

            if ($parents !== []) {
                $queue[] = [$fk['foreign_table'], $parents, $depth + 1];
            }
        }
    }

    /**
     * @param  array<string, array{depth: int, rows: list<array<string, mixed>>}>  $tables
     * @return list<array{table: string, depth: int, primary_key: string|null, rows: list<array<string, mixed>>}>
     */
    private function orderedResult(array $tables): array
    {
        $result = [];

        foreach ($tables as $name => $data) {
            $primaryKeyColumn = $this->primaryKeyColumn($name);

            $result[] = [
                'table' => $name,
                'depth' => $data['depth'],
                'primary_key' => $primaryKeyColumn,
                'rows' => $this->sortedRows($data['rows'], $primaryKeyColumn),
            ];
        }

        usort($result, fn (array $a, array $b) => ($b['depth'] <=> $a['depth'])
            ?: strcmp($a['table'], $b['table']));

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortedRows(array $rows, ?string $primaryKeyColumn): array
    {
        $rows = array_values($rows);

        usort($rows, function (array $a, array $b) use ($primaryKeyColumn): int {
            $aIdentity = $primaryKeyColumn === null ? $a : ($a[$primaryKeyColumn] ?? null);
            $bIdentity = $primaryKeyColumn === null ? $b : ($b[$primaryKeyColumn] ?? null);

            return strcmp(serialize($aIdentity), serialize($bIdentity));
        });

        return $rows;
    }

    /**
     * The single-column primary key of a table, or null when the table has
     * none (row capture requires one on the root table).
     */
    public function primaryKeyColumn(string $table): ?string
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['primary'] ?? false) && count($index['columns']) === 1) {
                return $index['columns'][0];
            }
        }

        return null;
    }

    /**
     * @return list<array{columns: list<string>, foreign_table: string, foreign_columns: list<string>}>
     */
    private function outgoingForeignKeys(string $table): array
    {
        $foreignKeys = [];

        foreach (Schema::getForeignKeys($table) as $fk) {
            $foreignKeys[] = [
                'columns' => array_values($fk['columns']),
                'foreign_table' => $fk['foreign_table'],
                'foreign_columns' => array_values($fk['foreign_columns']),
            ];
        }

        return $foreignKeys;
    }

    /**
     * Fetch exactly the referenced parent tuples for one foreign key.
     *
     * @param  array{columns: list<string>, foreign_table: string, foreign_columns: list<string>}  $foreignKey
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function parentRows(array $foreignKey, array $rows): array
    {
        $tuples = $this->referencedTuples($foreignKey, $rows);

        if ($tuples === []) {
            return [];
        }

        if (count($foreignKey['columns']) === 1) {
            return DB::table($foreignKey['foreign_table'])
                ->whereIn($foreignKey['foreign_columns'][0], array_column($tuples, 0))
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();
        }

        return $this->compositeParentRows($foreignKey, array_values($tuples));
    }

    /**
     * @param  array{columns: list<string>, foreign_table: string, foreign_columns: list<string>}  $foreignKey
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, list<mixed>>
     */
    private function referencedTuples(array $foreignKey, array $rows): array
    {
        $tuples = [];

        foreach ($rows as $row) {
            $tuple = $this->referencedTuple($foreignKey['columns'], $row);

            if ($tuple !== null) {
                $tuples[hash('sha256', serialize($tuple))] = $tuple;
            }
        }

        return $tuples;
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $row
     * @return list<mixed>|null
     */
    private function referencedTuple(array $columns, array $row): ?array
    {
        $tuple = [];

        foreach ($columns as $column) {
            $value = $row[$column] ?? null;

            if ($value === null) {
                return null;
            }

            $tuple[] = $value;
        }

        return $tuple;
    }

    /**
     * @param  array{columns: list<string>, foreign_table: string, foreign_columns: list<string>}  $foreignKey
     * @param  list<list<mixed>>  $tuples
     * @return list<array<string, mixed>>
     */
    private function compositeParentRows(array $foreignKey, array $tuples): array
    {
        $parents = [];
        $chunkSize = max(1, intdiv(500, count($foreignKey['columns'])));

        foreach (array_chunk($tuples, $chunkSize) as $chunk) {
            $batch = DB::table($foreignKey['foreign_table'])
                ->where(fn (Builder $query) => $this->whereCompositeTuples($query, $foreignKey, $chunk))
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();

            array_push($parents, ...$batch);
        }

        return $parents;
    }

    /**
     * @param  array{columns: list<string>, foreign_table: string, foreign_columns: list<string>}  $foreignKey
     * @param  list<list<mixed>>  $tuples
     */
    private function whereCompositeTuples(Builder $query, array $foreignKey, array $tuples): void
    {
        foreach ($tuples as $tuple) {
            $query->orWhere(function (Builder $candidate) use ($tuple, $foreignKey): void {
                foreach ($foreignKey['foreign_columns'] as $index => $column) {
                    $candidate->where($column, $tuple[$index]);
                }
            });
        }
    }

    private function rowIdentity(array $row): string
    {
        return hash('sha256', serialize($row));
    }
}
