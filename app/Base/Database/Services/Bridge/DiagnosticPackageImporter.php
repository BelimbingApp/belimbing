<?php

namespace App\Base\Database\Services\Bridge;

use App\Base\Database\Exceptions\BridgeImportException;
use App\Base\Database\Services\DevelopmentInstanceGuard;
use App\Base\Database\Services\TableInspector;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Validates and applies byte-exact diagnostic packages on development only.
 *
 * Domain models and events are bypassed deliberately: this is a diagnostic
 * state reproduction tool, not a domain import. Every write is bounded,
 * schema-checked, primary-key addressed, parent-first, and transactional.
 */
class DiagnosticPackageImporter
{
    public function __construct(
        private readonly DiagnosticPackageInbox $inbox,
        private readonly TableInspector $inspector,
        private readonly DevelopmentInstanceGuard $environment,
        private readonly BridgeSettings $settings,
    ) {}

    /**
     * @return array{package_id: string, package_sha256: string, size_bytes: int, tables: list<array{table: string, rows: int, inserts: int, updates: int}>, total_rows: int}
     */
    public function inspect(string $packageId): array
    {
        $this->environment->assertDevelopment(__('Diagnostic package import'));

        $incoming = $this->inbox->open($packageId);
        $tables = $this->validatedTables($incoming['contents'], $packageId);
        $summary = [];

        foreach ($tables as $entry) {
            $inserts = 0;
            $updates = 0;

            foreach ($entry['rows'] as $row) {
                $this->rowExists($entry['table'], $entry['primary_keys'], $row)
                    ? $updates++
                    : $inserts++;
            }

            $summary[] = [
                'table' => $entry['table'],
                'rows' => count($entry['rows']),
                'inserts' => $inserts,
                'updates' => $updates,
            ];
        }

        return [
            'package_id' => $packageId,
            'package_sha256' => $incoming['receipt']['package_sha256'],
            'size_bytes' => $incoming['receipt']['size_bytes'],
            'tables' => $summary,
            'total_rows' => array_sum(array_column($summary, 'rows')),
        ];
    }

    /**
     * @return array{package_id: string, package_sha256: string, inserted: int, updated: int, tables: list<array{table: string, inserted: int, updated: int}>}
     */
    public function apply(string $packageId, string $expectedPackageSha256): array
    {
        $this->environment->assertDevelopment(__('Diagnostic package import'));

        $incoming = $this->inbox->open($packageId);

        if (! hash_equals($expectedPackageSha256, $incoming['receipt']['package_sha256'])) {
            throw BridgeImportException::previewChanged();
        }

        $tables = $this->validatedTables($incoming['contents'], $packageId);

        $result = DB::transaction(function () use ($tables): array {
            $tableResults = [];
            $totalInserted = 0;
            $totalUpdated = 0;

            foreach ($tables as $entry) {
                $inserted = 0;
                $updated = 0;
                $excluded = array_fill_keys([
                    ...$entry['redacted_columns'],
                    ...$entry['generated_columns'],
                ], true);

                foreach ($entry['rows'] as $row) {
                    $exists = $this->rowExists($entry['table'], $entry['primary_keys'], $row);
                    $values = array_diff_key($row, $excluded);

                    if ($exists) {
                        $updates = array_diff_key($values, array_fill_keys($entry['primary_keys'], true));

                        if ($updates !== []) {
                            $this->primaryKeyQuery($entry['table'], $entry['primary_keys'], $row)->update($updates);
                        }

                        $updated++;

                        continue;
                    }

                    $this->assertInsertable($entry, $values);
                    DB::table($entry['table'])->insert($values);
                    $inserted++;
                }

                $totalInserted += $inserted;
                $totalUpdated += $updated;
                $tableResults[] = [
                    'table' => $entry['table'],
                    'inserted' => $inserted,
                    'updated' => $updated,
                ];
            }

            return [
                'inserted' => $totalInserted,
                'updated' => $totalUpdated,
                'tables' => $tableResults,
            ];
        });

        return [
            'package_id' => $packageId,
            'package_sha256' => $incoming['receipt']['package_sha256'],
            ...$result,
        ];
    }

    /**
     * @return list<array{table: string, depth: int, primary_keys: list<string>, redacted_columns: list<string>, generated_columns: list<string>, columns: list<array<string, mixed>>, rows: list<array<string, mixed>>}>
     */
    private function validatedTables(string $contents, string $packageId): array
    {
        try {
            $package = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw BridgeImportException::invalidPackage(__('JSON cannot be decoded.'));
        }

        if (! is_array($package)
            || ($package['format'] ?? null) !== DiagnosticRowCapture::FORMAT
            || ($package['format_version'] ?? null) !== DiagnosticRowCapture::FORMAT_VERSION
            || ($package['package_id'] ?? null) !== $packageId
            || ($package['marker'] ?? null) !== 'diagnostic'
            || ($package['import_policy'] ?? null) !== 'development-only'
            || ! is_array($package['source'] ?? null)
            || ! is_string($package['source']['app_env'] ?? null)
            || ! is_string($package['source']['driver'] ?? null)) {
            throw BridgeImportException::invalidPackage(__('the format, identifier, or development-only marker does not match.'));
        }

        $packageTables = $package['tables'] ?? null;

        if (! is_array($packageTables) || ! array_is_list($packageTables) || $packageTables === []) {
            throw BridgeImportException::invalidPackage(__('the table payload is missing.'));
        }

        $maxTables = $this->settings->integer('bridge.limits.max_tables', 100, 1, 10000);
        $maxRows = $this->settings->integer('bridge.limits.max_closure_rows', 5000, 1, 1000000);
        $maxDepth = $this->settings->integer('bridge.limits.max_closure_depth', 8, 1, 64);

        if (count($packageTables) > $maxTables) {
            throw BridgeImportException::invalidPackage(__('the table count exceeds the configured limit.'));
        }

        $payloadSha256 = $package['payload_sha256'] ?? null;

        if (! is_string($payloadSha256)
            || preg_match('/\A[0-9a-f]{64}\z/', $payloadSha256) !== 1
            || ! hash_equals($payloadSha256, hash('sha256', CanonicalJson::encode($packageTables)))) {
            throw BridgeImportException::invalidPackage(__('the payload hash does not match.'));
        }

        $tables = [];
        $seenTables = [];
        $totalRows = 0;

        foreach ($packageTables as $entry) {
            $table = is_array($entry) ? ($entry['table'] ?? null) : null;

            if (! is_string($table)
                || preg_match('/\A[A-Za-z_]\w*\z/', $table) !== 1
                || isset($seenTables[$table])) {
                throw BridgeImportException::invalidPackage(__('a table name is invalid or duplicated.'));
            }

            if (! $this->inspector->isRegistered($table)) {
                throw BridgeImportException::unsupportedTable($table);
            }

            $seenTables[$table] = true;
            $columns = Schema::getColumns($table);
            $columnNames = array_column($columns, 'name');
            $columnSet = array_fill_keys($columnNames, true);
            $generatedColumns = array_values(array_map(
                fn (array $column): string => (string) $column['name'],
                array_filter($columns, fn (array $column): bool => ($column['generation'] ?? null) !== null),
            ));
            $primaryKeys = $this->primaryKeys($table);
            $declaredPrimaryKey = $entry['primary_key'] ?? null;

            if (count($primaryKeys) === 1 && $declaredPrimaryKey !== $primaryKeys[0]) {
                throw BridgeImportException::incompatibleSchema($table, __('the primary key changed.'));
            }

            if (count($primaryKeys) > 1 && $declaredPrimaryKey !== null) {
                throw BridgeImportException::incompatibleSchema($table, __('the composite primary key metadata is incompatible.'));
            }

            $redactedColumns = $this->stringList($entry['redacted_columns'] ?? null, $table, __('redacted columns'));

            foreach ($redactedColumns as $column) {
                if (! isset($columnSet[$column])) {
                    throw BridgeImportException::incompatibleSchema($table, __('redacted column :column is missing.', ['column' => $column]));
                }
            }

            $rows = $entry['rows'] ?? null;

            if (! is_array($rows) || ! array_is_list($rows) || $rows === []) {
                throw BridgeImportException::invalidPackage(__('table :table has no row list.', ['table' => $table]));
            }

            $decodedRows = [];
            $seenPrimaryKeys = [];

            foreach ($rows as $row) {
                $decoded = $this->decodeRow($table, $row, $columnSet);
                $identity = [];

                foreach ($primaryKeys as $primaryKey) {
                    if (! array_key_exists($primaryKey, $decoded) || $decoded[$primaryKey] === null) {
                        throw BridgeImportException::incompatibleSchema($table, __('a row is missing primary key :column.', ['column' => $primaryKey]));
                    }

                    $identity[] = $decoded[$primaryKey];
                }

                $identityHash = hash('sha256', serialize($identity));

                if (isset($seenPrimaryKeys[$identityHash])) {
                    throw BridgeImportException::invalidPackage(__('table :table repeats a primary key.', ['table' => $table]));
                }

                $seenPrimaryKeys[$identityHash] = true;
                $decodedRows[] = $decoded;

                if (++$totalRows > $maxRows) {
                    throw BridgeImportException::invalidPackage(__('the row count exceeds the configured limit.'));
                }
            }

            $depth = $entry['depth'] ?? null;

            if (! is_int($depth) || $depth < 0 || $depth > $maxDepth) {
                throw BridgeImportException::invalidPackage(__('table :table has an invalid dependency depth.', ['table' => $table]));
            }

            $tables[] = [
                'table' => $table,
                'depth' => $depth,
                'primary_keys' => $primaryKeys,
                'redacted_columns' => $redactedColumns,
                'generated_columns' => $generatedColumns,
                'columns' => $columns,
                'rows' => $decodedRows,
            ];
        }

        if (($package['counts']['tables'] ?? null) !== count($tables)
            || ($package['counts']['rows'] ?? null) !== $totalRows) {
            throw BridgeImportException::invalidPackage(__('the manifest counts do not match the payload.'));
        }

        $this->assertSelection($package['selection'] ?? null, $tables);
        $this->assertParentFirst($tables);

        return $tables;
    }

    /**
     * @param  array<string, true>  $columnSet
     * @return array<string, mixed>
     */
    private function decodeRow(string $table, mixed $row, array $columnSet): array
    {
        if (! is_array($row) || array_is_list($row) || $row === []) {
            throw BridgeImportException::invalidPackage(__('table :table contains an invalid row.', ['table' => $table]));
        }

        $decoded = [];
        $maxScalarBytes = $this->settings->integer('bridge.limits.max_scalar_bytes', 5 * 1024 * 1024, 1, 2147483647);

        foreach ($row as $column => $value) {
            if (! is_string($column) || ! isset($columnSet[$column])) {
                throw BridgeImportException::incompatibleSchema($table, __('column :column is missing.', ['column' => (string) $column]));
            }

            if (is_array($value)) {
                if (array_keys($value) !== ['__b64'] || ! is_string($value['__b64'])) {
                    throw BridgeImportException::invalidPackage(__('table :table contains an unsupported structured value.', ['table' => $table]));
                }

                $value = base64_decode($value['__b64'], true);

                if ($value === false) {
                    throw BridgeImportException::invalidPackage(__('table :table contains invalid base64.', ['table' => $table]));
                }
            } elseif (! is_null($value) && ! is_scalar($value)) {
                throw BridgeImportException::invalidPackage(__('table :table contains an unsupported value.', ['table' => $table]));
            }

            if (is_float($value) && ! is_finite($value)) {
                throw BridgeImportException::invalidPackage(__('table :table contains a non-finite number.', ['table' => $table]));
            }

            if (is_string($value) && strlen($value) > $maxScalarBytes) {
                throw BridgeImportException::invalidPackage(__('table :table contains a scalar above the configured limit.', ['table' => $table]));
            }

            $decoded[$column] = $value;
        }

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function primaryKeys(string $table): array
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['primary'] ?? false) && ($index['columns'] ?? []) !== []) {
                return array_values($index['columns']);
            }
        }

        throw BridgeImportException::incompatibleSchema($table, __('no primary key exists.'));
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value, string $table, string $label): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw BridgeImportException::invalidPackage(__('table :table has invalid :label.', [
                'table' => $table,
                'label' => $label,
            ]));
        }

        $strings = array_values(array_unique(array_filter($value, is_string(...))));

        if (count($strings) !== count($value)) {
            throw BridgeImportException::invalidPackage(__('table :table has invalid :label.', [
                'table' => $table,
                'label' => $label,
            ]));
        }

        return $strings;
    }

    /**
     * @param  list<array{table: string}>  $tables
     */
    private function assertParentFirst(array $tables): void
    {
        $positions = [];

        foreach ($tables as $position => $entry) {
            $positions[$entry['table']] = $position;
        }

        foreach ($tables as $position => $entry) {
            foreach (Schema::getForeignKeys($entry['table']) as $foreignKey) {
                $parent = $foreignKey['foreign_table'];

                if ($parent !== $entry['table']
                    && isset($positions[$parent])
                    && $positions[$parent] > $position) {
                    throw BridgeImportException::invalidPackage(__('dependency tables are not ordered parents first.'));
                }
            }
        }
    }

    /**
     * @param  list<array{table: string, primary_keys: list<string>, rows: list<array<string, mixed>>}>  $tables
     */
    private function assertSelection(mixed $selection, array $tables): void
    {
        if (! is_array($selection)
            || ! is_string($selection['table'] ?? null)
            || ! is_string($selection['primary_key'] ?? null)
            || ! is_array($selection['ids'] ?? null)
            || ! array_is_list($selection['ids'])
            || $selection['ids'] === []
            || count($selection['ids']) > $this->settings->integer('bridge.limits.max_selected_rows', 100, 1, 10000)) {
            throw BridgeImportException::invalidPackage(__('the root selection is invalid.'));
        }

        $root = collect($tables)->firstWhere('table', $selection['table']);

        if (! is_array($root)
            || $root['primary_keys'] !== [$selection['primary_key']]) {
            throw BridgeImportException::invalidPackage(__('the root selection does not match its destination table.'));
        }

        $rowIds = array_fill_keys(array_map(
            fn (array $row): string => (string) $row[$selection['primary_key']],
            $root['rows'],
        ), true);
        $selectionIds = [];

        foreach ($selection['ids'] as $id) {
            if (! is_int($id) && ! is_string($id)) {
                throw BridgeImportException::invalidPackage(__('the root selection contains an invalid identifier.'));
            }

            $id = (string) $id;

            if (isset($selectionIds[$id]) || ! isset($rowIds[$id])) {
                throw BridgeImportException::invalidPackage(__('the root selection does not match the included rows.'));
            }

            $selectionIds[$id] = true;
        }
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
                throw BridgeImportException::redactedRequiredColumn($entry['table'], $name);
            }

            throw BridgeImportException::incompatibleSchema(
                $entry['table'],
                __('required destination column :column has no package value or default.', ['column' => $name]),
            );
        }
    }
}
