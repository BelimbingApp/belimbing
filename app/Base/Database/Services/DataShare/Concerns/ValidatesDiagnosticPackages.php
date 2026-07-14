<?php

namespace App\Base\Database\Services\DataShare\Concerns;

use App\Base\Database\Exceptions\DataShareImportException;
use App\Base\Database\Services\DataShare\CanonicalJson;
use App\Base\Database\Services\DataShare\DiagnosticRowCapture;
use Illuminate\Support\Facades\Schema;

trait ValidatesDiagnosticPackages
{
    /**
     * @return list<array{table: string, depth: int, primary_keys: list<string>, redacted_columns: list<string>, generated_columns: list<string>, columns: list<array<string, mixed>>, rows: list<array<string, mixed>>}>
     */
    private function validatedTables(string $contents, string $packageId): array
    {
        $package = $this->decodePackage($contents, $packageId);
        $packageTables = $this->packageTables($package);

        $maxTables = $this->diagnosticSettings()->integer('data_share.limits.max_tables', 100, 1, 10000);
        $maxRows = $this->diagnosticSettings()->integer('data_share.limits.max_closure_rows', 5000, 1, 1000000);
        $maxDepth = $this->diagnosticSettings()->integer('data_share.limits.max_closure_depth', 8, 1, 64);

        if (count($packageTables) > $maxTables) {
            throw DataShareImportException::invalidPackage(__('the table count exceeds the configured limit.'));
        }

        $this->assertPayloadHash($package, $packageTables);

        $tables = [];
        $seenTables = [];
        $totalRows = 0;

        foreach ($packageTables as $entry) {
            $tables[] = $this->validatedTableEntry($entry, $seenTables, $totalRows, $maxRows, $maxDepth);
        }

        if (($package['counts']['tables'] ?? null) !== count($tables)
            || ($package['counts']['rows'] ?? null) !== $totalRows) {
            throw DataShareImportException::invalidPackage(__('the manifest counts do not match the payload.'));
        }

        $this->assertSelection($package['selection'] ?? null, $tables);
        $this->assertParentFirst($tables);

        return $tables;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePackage(string $contents, string $packageId): array
    {
        try {
            $package = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw DataShareImportException::invalidPackage(__('JSON cannot be decoded.'));
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
            throw DataShareImportException::invalidPackage(__('the format, identifier, or development-only marker does not match.'));
        }

        return $package;
    }

    /**
     * @param  array<string, mixed>  $package
     * @return list<array<string, mixed>>
     */
    private function packageTables(array $package): array
    {
        $packageTables = $package['tables'] ?? null;

        if (! is_array($packageTables) || ! array_is_list($packageTables) || $packageTables === []) {
            throw DataShareImportException::invalidPackage(__('the table payload is missing.'));
        }

        return $packageTables;
    }

    /**
     * @param  array<string, mixed>  $package
     * @param  list<array<string, mixed>>  $packageTables
     */
    private function assertPayloadHash(array $package, array $packageTables): void
    {
        $payloadSha256 = $package['payload_sha256'] ?? null;

        if (! is_string($payloadSha256)
            || preg_match('/\A[0-9a-f]{64}\z/', $payloadSha256) !== 1
            || ! hash_equals($payloadSha256, hash('sha256', CanonicalJson::encode($packageTables)))) {
            throw DataShareImportException::invalidPackage(__('the payload hash does not match.'));
        }
    }

    /**
     * @param  array<string, true>  $seenTables
     * @return array{table: string, depth: int, primary_keys: list<string>, redacted_columns: list<string>, generated_columns: list<string>, columns: list<array<string, mixed>>, rows: list<array<string, mixed>>}
     */
    private function validatedTableEntry(
        mixed $entry,
        array &$seenTables,
        int &$totalRows,
        int $maxRows,
        int $maxDepth,
    ): array {
        $entry = is_array($entry) ? $entry : [];
        $table = $this->validatedTableName($entry, $seenTables);
        $columns = Schema::getColumns($table);
        $columnSet = array_fill_keys(array_column($columns, 'name'), true);
        $generatedColumns = $this->generatedColumns($columns);
        $primaryKeys = $this->primaryKeys($table);

        $this->assertPrimaryKeyMetadata($entry, $table, $primaryKeys);

        $redactedColumns = $this->stringList($entry['redacted_columns'] ?? null, $table, __('redacted columns'));
        $this->assertKnownColumns($table, $redactedColumns, $columnSet);

        return [
            'table' => $table,
            'depth' => $this->validatedDepth($entry, $table, $maxDepth),
            'primary_keys' => $primaryKeys,
            'redacted_columns' => $redactedColumns,
            'generated_columns' => $generatedColumns,
            'columns' => $columns,
            'rows' => $this->decodedRows($entry, $table, $columnSet, $primaryKeys, $totalRows, $maxRows),
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, true>  $seenTables
     */
    private function validatedTableName(array $entry, array &$seenTables): string
    {
        $table = $entry['table'] ?? null;

        if (! is_string($table)
            || preg_match('/\A[A-Za-z_]\w*\z/', $table) !== 1
            || isset($seenTables[$table])) {
            throw DataShareImportException::invalidPackage(__('a table name is invalid or duplicated.'));
        }

        if (! $this->diagnosticTableInspector()->isRegistered($table)) {
            throw DataShareImportException::unsupportedTable($table);
        }

        $seenTables[$table] = true;

        return $table;
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @return list<string>
     */
    private function generatedColumns(array $columns): array
    {
        return array_values(array_map(
            fn (array $column): string => (string) $column['name'],
            array_filter($columns, fn (array $column): bool => ($column['generation'] ?? null) !== null),
        ));
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $primaryKeys
     */
    private function assertPrimaryKeyMetadata(array $entry, string $table, array $primaryKeys): void
    {
        $declaredPrimaryKey = $entry['primary_key'] ?? null;

        if (count($primaryKeys) === 1 && $declaredPrimaryKey !== $primaryKeys[0]) {
            throw DataShareImportException::incompatibleSchema($table, __('the primary key changed.'));
        }

        if (count($primaryKeys) > 1 && $declaredPrimaryKey !== null) {
            throw DataShareImportException::incompatibleSchema($table, __('the composite primary key metadata is incompatible.'));
        }
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, true>  $columnSet
     */
    private function assertKnownColumns(string $table, array $columns, array $columnSet): void
    {
        foreach ($columns as $column) {
            if (! isset($columnSet[$column])) {
                throw DataShareImportException::incompatibleSchema($table, __('redacted column :column is missing.', ['column' => $column]));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function validatedDepth(array $entry, string $table, int $maxDepth): int
    {
        $depth = $entry['depth'] ?? null;

        if (! is_int($depth) || $depth < 0 || $depth > $maxDepth) {
            throw DataShareImportException::invalidPackage(__('table :table has an invalid dependency depth.', ['table' => $table]));
        }

        return $depth;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, true>  $columnSet
     * @param  list<string>  $primaryKeys
     * @return list<array<string, mixed>>
     */
    private function decodedRows(
        array $entry,
        string $table,
        array $columnSet,
        array $primaryKeys,
        int &$totalRows,
        int $maxRows,
    ): array {
        $rows = $entry['rows'] ?? null;

        if (! is_array($rows) || ! array_is_list($rows) || $rows === []) {
            throw DataShareImportException::invalidPackage(__('table :table has no row list.', ['table' => $table]));
        }

        $decodedRows = [];
        $seenPrimaryKeys = [];

        foreach ($rows as $row) {
            $decoded = $this->decodeRow($table, $row, $columnSet);
            $this->assertUniquePrimaryKey($table, $decoded, $primaryKeys, $seenPrimaryKeys);
            $decodedRows[] = $decoded;

            if (++$totalRows > $maxRows) {
                throw DataShareImportException::invalidPackage(__('the row count exceeds the configured limit.'));
            }
        }

        return $decodedRows;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  list<string>  $primaryKeys
     * @param  array<string, true>  $seenPrimaryKeys
     */
    private function assertUniquePrimaryKey(string $table, array $decoded, array $primaryKeys, array &$seenPrimaryKeys): void
    {
        $identity = [];

        foreach ($primaryKeys as $primaryKey) {
            if (! array_key_exists($primaryKey, $decoded) || $decoded[$primaryKey] === null) {
                throw DataShareImportException::incompatibleSchema($table, __('a row is missing primary key :column.', ['column' => $primaryKey]));
            }

            $identity[] = $decoded[$primaryKey];
        }

        $identityHash = hash('sha256', serialize($identity));

        if (isset($seenPrimaryKeys[$identityHash])) {
            throw DataShareImportException::invalidPackage(__('table :table repeats a primary key.', ['table' => $table]));
        }

        $seenPrimaryKeys[$identityHash] = true;
    }

    /**
     * @param  array<string, true>  $columnSet
     * @return array<string, mixed>
     */
    private function decodeRow(string $table, mixed $row, array $columnSet): array
    {
        if (! is_array($row) || array_is_list($row) || $row === []) {
            throw DataShareImportException::invalidPackage(__('table :table contains an invalid row.', ['table' => $table]));
        }

        $decoded = [];
        $maxScalarBytes = $this->diagnosticSettings()->integer('data_share.limits.max_scalar_bytes', 5 * 1024 * 1024, 1, 2147483647);

        foreach ($row as $column => $value) {
            if (! is_string($column) || ! isset($columnSet[$column])) {
                throw DataShareImportException::incompatibleSchema($table, __('column :column is missing.', ['column' => (string) $column]));
            }

            $decoded[$column] = $this->decodedValue($table, $value, $maxScalarBytes);
        }

        return $decoded;
    }

    private function decodedValue(string $table, mixed $value, int $maxScalarBytes): mixed
    {
        $value = is_array($value)
            ? $this->decodedBinaryEnvelope($table, $value)
            : $value;

        if (! is_null($value) && ! is_scalar($value)) {
            throw DataShareImportException::invalidPackage(__('table :table contains an unsupported value.', ['table' => $table]));
        }

        if (is_float($value) && ! is_finite($value)) {
            throw DataShareImportException::invalidPackage(__('table :table contains a non-finite number.', ['table' => $table]));
        }

        if (is_string($value) && strlen($value) > $maxScalarBytes) {
            throw DataShareImportException::invalidPackage(__('table :table contains a scalar above the configured limit.', ['table' => $table]));
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function decodedBinaryEnvelope(string $table, array $value): string
    {
        if (array_keys($value) !== ['__b64'] || ! is_string($value['__b64'])) {
            throw DataShareImportException::invalidPackage(__('table :table contains an unsupported structured value.', ['table' => $table]));
        }

        $decoded = base64_decode($value['__b64'], true);

        if ($decoded === false) {
            throw DataShareImportException::invalidPackage(__('table :table contains invalid base64.', ['table' => $table]));
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

        throw DataShareImportException::incompatibleSchema($table, __('no primary key exists.'));
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value, string $table, string $label): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw DataShareImportException::invalidPackage(__('table :table has invalid :label.', [
                'table' => $table,
                'label' => $label,
            ]));
        }

        $strings = array_values(array_unique(array_filter($value, is_string(...))));

        if (count($strings) !== count($value)) {
            throw DataShareImportException::invalidPackage(__('table :table has invalid :label.', [
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
                    throw DataShareImportException::invalidPackage(__('dependency tables are not ordered parents first.'));
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
            || count($selection['ids']) > $this->diagnosticSettings()->integer('data_share.limits.max_selected_rows', 100, 1, 10000)) {
            throw DataShareImportException::invalidPackage(__('the root selection is invalid.'));
        }

        $root = collect($tables)->firstWhere('table', $selection['table']);

        if (! is_array($root)
            || $root['primary_keys'] !== [$selection['primary_key']]) {
            throw DataShareImportException::invalidPackage(__('the root selection does not match its destination table.'));
        }

        $rowIds = array_fill_keys(array_map(
            fn (array $row): string => (string) $row[$selection['primary_key']],
            $root['rows'],
        ), true);
        $selectionIds = [];

        foreach ($selection['ids'] as $id) {
            if (! is_int($id) && ! is_string($id)) {
                throw DataShareImportException::invalidPackage(__('the root selection contains an invalid identifier.'));
            }

            $id = (string) $id;

            if (isset($selectionIds[$id]) || ! isset($rowIds[$id])) {
                throw DataShareImportException::invalidPackage(__('the root selection does not match the included rows.'));
            }

            $selectionIds[$id] = true;
        }
    }
}
