<?php

namespace App\Base\Database\Services\DataShare\Concerns;

use App\Base\Database\Exceptions\DataShareImportException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

trait AppliesDiagnosticPackages
{
    /**
     * @param  list<array{table: string, primary_keys: list<string>, redacted_columns: list<string>, generated_columns: list<string>, columns: list<array<string, mixed>>, rows: list<array<string, mixed>>}>  $tables
     * @return array{inserted: int, updated: int, tables: list<array{table: string, inserted: int, updated: int}>}
     */
    private function applyTables(array $tables): array
    {
        $tableResults = [];
        $totalInserted = 0;
        $totalUpdated = 0;

        foreach ($tables as $entry) {
            $result = $this->applyTable($entry);
            $totalInserted += $result['inserted'];
            $totalUpdated += $result['updated'];
            $tableResults[] = $result;
        }

        return [
            'inserted' => $totalInserted,
            'updated' => $totalUpdated,
            'tables' => $tableResults,
        ];
    }

    /**
     * @param  array{table: string, primary_keys: list<string>, redacted_columns: list<string>, generated_columns: list<string>, columns: list<array<string, mixed>>, rows: list<array<string, mixed>>}  $entry
     * @return array{table: string, inserted: int, updated: int}
     */
    private function applyTable(array $entry): array
    {
        $inserted = 0;
        $updated = 0;
        $excluded = array_fill_keys([...$entry['redacted_columns'], ...$entry['generated_columns']], true);

        foreach ($entry['rows'] as $row) {
            $this->applyRow($entry, array_diff_key($row, $excluded), $row)
                ? $updated++
                : $inserted++;
        }

        return [
            'table' => $entry['table'],
            'inserted' => $inserted,
            'updated' => $updated,
        ];
    }

    /**
     * @param  array{table: string, primary_keys: list<string>, redacted_columns: list<string>, generated_columns: list<string>, columns: list<array<string, mixed>>}  $entry
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $row
     * @return bool true when the row updated an existing record
     */
    private function applyRow(array $entry, array $values, array $row): bool
    {
        if ($this->rowExists($entry['table'], $entry['primary_keys'], $row)) {
            $updates = array_diff_key($values, array_fill_keys($entry['primary_keys'], true));

            if ($updates !== []) {
                $this->primaryKeyQuery($entry['table'], $entry['primary_keys'], $row)->update($updates);
            }

            return true;
        }

        $this->assertInsertable($entry, $values);
        DB::table($entry['table'])->insert($values);

        return false;
    }

    /**
     * @param  list<string>  $primaryKeys
     * @param  array<string, mixed>  $row
     */
    private function rowExists(string $table, array $primaryKeys, array $row): bool
    {
        return $this->primaryKeyQuery($table, $primaryKeys, $row)->exists();
    }

    /**
     * @param  list<string>  $primaryKeys
     * @param  array<string, mixed>  $row
     */
    private function primaryKeyQuery(string $table, array $primaryKeys, array $row): Builder
    {
        $query = DB::table($table);

        foreach ($primaryKeys as $primaryKey) {
            $query->where($primaryKey, $row[$primaryKey]);
        }

        return $query;
    }

    /**
     * @param  array{table: string, redacted_columns: list<string>, generated_columns: list<string>, columns: list<array<string, mixed>>}  $entry
     * @param  array<string, mixed>  $values
     */
    private function assertInsertable(array $entry, array $values): void
    {
        foreach ($entry['columns'] as $column) {
            $name = (string) $column['name'];

            if (array_key_exists($name, $values)
                || in_array($name, $entry['generated_columns'], true)
                || ($column['nullable'] ?? false)
                || ($column['default'] ?? null) !== null
                || ($column['auto_increment'] ?? false)) {
                continue;
            }

            if (in_array($name, $entry['redacted_columns'], true)) {
                throw DataShareImportException::redactedRequiredColumn($entry['table'], $name);
            }

            throw DataShareImportException::incompatibleSchema(
                $entry['table'],
                __('required destination column :column has no package value or default.', ['column' => $name]),
            );
        }
    }
}
