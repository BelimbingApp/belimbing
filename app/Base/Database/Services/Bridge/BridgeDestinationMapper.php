<?php

namespace App\Base\Database\Services\Bridge;

use App\Base\Database\DTO\Bridge\BridgeTableDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BridgeDestinationMapper
{
    /** @var array<string, array<string, array<string, true>>> */
    private array $knownReferences = [];

    /** @var array<string, list<list<string>>> */
    private array $targetReferenceColumns = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $uniqueIndexes = [];

    public function __construct(
        private readonly BridgeValueNormalizer $values,
        private readonly BridgeScopeCatalog $catalog,
    ) {}

    public function reset(): void
    {
        $this->knownReferences = [];
        $this->uniqueIndexes = [];
        $targets = [];

        foreach ($this->catalog->scopes() as $scope) {
            foreach ($scope->tables as $table) {
                foreach ($table->references as $reference) {
                    $targets[$reference->targetTable][CanonicalJson::encode($reference->targetColumns)] = $reference->targetColumns;
                }
            }
        }

        $this->targetReferenceColumns = array_map(fn (array $sets): array => array_values($sets), $targets);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{action: string, destination_fingerprint: ?string}
     */
    public function classify(BridgeTableDefinition $table, array $record): array
    {
        $existing = $this->findExisting($table, $record);

        if ($existing === null) {
            $desired = $this->desiredValues($table, $record);

            if (! $this->referencesResolvable($table, $desired) || $this->hasUniqueCollision($table, $desired)) {
                return ['action' => 'conflict', 'destination_fingerprint' => null];
            }

            $this->remember($table, $desired);

            return ['action' => 'insert', 'destination_fingerprint' => null];
        }

        $this->remember($table, $existing);
        $fingerprint = $this->rowFingerprint($table, $existing);

        return [
            'action' => $this->rowMatches($table, $existing, $record['values']) ? 'unchanged' : 'conflict',
            'destination_fingerprint' => $fingerprint,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>|null
     */
    public function findExisting(BridgeTableDefinition $table, array $record): ?array
    {
        $query = DB::table($table->table);

        foreach ($record['primary_key'] as $column => $value) {
            $this->applyWhere($query, $table->table, $column, $this->values->decode($table->table, $column, $value));
        }

        $row = $query->first();

        return $row === null ? null : (array) $row;
    }

    /** @param array<string, mixed> $record */
    public function desiredValues(BridgeTableDefinition $table, array $record): array
    {
        $desired = [];

        foreach ($record['values'] as $column => $value) {
            $desired[$column] = $this->values->decode($table->table, $column, $value);
        }

        return $desired;
    }

    /** @param array<string, mixed> $row */
    public function remember(BridgeTableDefinition $table, array $row): void
    {
        foreach ($this->targetReferenceColumns[$table->table] ?? [] as $columns) {
            $values = array_map(fn (string $column): mixed => $row[$column] ?? null, $columns);
            $columnsKey = CanonicalJson::encode($columns);
            $valuesKey = CanonicalJson::encode($this->normalizeReferenceValues($table->table, $columns, $values));
            $this->knownReferences[$table->table][$columnsKey][$valuesKey] = true;
        }
    }

    /** @param array<string, mixed> $row */
    public function rowFingerprint(BridgeTableDefinition $table, array $row): string
    {
        $normalized = [];

        foreach ($row as $column => $value) {
            $normalized[$column] = $this->values->encode($table->table, $column, $value);
        }

        ksort($normalized, SORT_STRING);

        return hash('sha256', CanonicalJson::encode($normalized));
    }

    /** @param array<string, mixed> $desired */
    private function referencesResolvable(BridgeTableDefinition $table, array $desired): bool
    {
        foreach ($table->references as $reference) {
            $values = array_map(fn (string $column): mixed => $desired[$column] ?? null, $reference->localColumns);
            $nonnull = array_filter($values, fn (mixed $value): bool => $value !== null);

            if ($nonnull === [] && $reference->nullable) {
                continue;
            }

            if (count($nonnull) !== count($values)) {
                return false;
            }

            $targetValues = $this->normalizeReferenceValues($reference->targetTable, $reference->targetColumns, $values);
            $columnsKey = CanonicalJson::encode($reference->targetColumns);
            $valuesKey = CanonicalJson::encode($targetValues);

            if (isset($this->knownReferences[$reference->targetTable][$columnsKey][$valuesKey])) {
                continue;
            }

            $query = DB::table($reference->targetTable);

            foreach ($reference->targetColumns as $index => $column) {
                $this->applyWhere($query, $reference->targetTable, $column, $values[$index]);
            }

            if (! $query->exists()) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $incoming */
    private function rowMatches(BridgeTableDefinition $table, array $row, array $incoming): bool
    {
        foreach ($incoming as $column => $value) {
            $destination = $this->values->encode($table->table, $column, $row[$column] ?? null);
            $package = $this->values->encode(
                $table->table,
                $column,
                $this->values->decode($table->table, $column, $value),
            );

            if ($destination !== $package) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $desired */
    private function hasUniqueCollision(BridgeTableDefinition $table, array $desired): bool
    {
        foreach ($this->uniqueIndexes($table->table) as $index) {
            if (collect($index['columns'])->contains(fn (string $column): bool => ($desired[$column] ?? null) === null)) {
                continue;
            }

            $query = DB::table($table->table);

            foreach ($index['columns'] as $column) {
                $this->applyWhere($query, $table->table, $column, $desired[$column] ?? null);
            }

            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    private function uniqueIndexes(string $table): array
    {
        return $this->uniqueIndexes[$table] ??= array_values(array_filter(
            Schema::getIndexes($table),
            fn (array $index): bool => (bool) $index['unique'] && ! (bool) $index['primary'],
        ));
    }

    /**
     * @param  list<string>  $columns
     * @param  list<mixed>  $values
     * @return list<mixed>
     */
    private function normalizeReferenceValues(string $table, array $columns, array $values): array
    {
        return array_map(
            fn (mixed $value, int $index): mixed => $this->values->encode($table, $columns[$index], $value),
            $values,
            array_keys($values),
        );
    }

    private function applyWhere($query, string $table, string $column, mixed $value): void
    {
        if ($value === null) {
            $query->whereNull($column);

            return;
        }

        if ($this->values->type($table, $column) === 'date') {
            $query->whereDate($column, $value);

            return;
        }

        $query->where($column, $value);
    }
}
