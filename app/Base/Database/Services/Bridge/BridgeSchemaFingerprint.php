<?php

namespace App\Base\Database\Services\Bridge;

use App\Base\Database\DTO\Bridge\BridgeTableDefinition;
use Illuminate\Support\Facades\Schema;

class BridgeSchemaFingerprint
{
    /** @return array{sha256: string, schema: array<string, mixed>} */
    public function forTable(BridgeTableDefinition $definition): array
    {
        $columns = array_map(fn (array $column): array => [
            'name' => $column['name'],
            'type' => $this->logicalType((string) $column['type_name']),
            'nullable' => (bool) $column['nullable'],
        ], Schema::getColumns($definition->table));
        $uniqueIndexes = array_values(array_map(fn (array $index): array => [
            'columns' => array_values($index['columns']),
            'unique' => true,
        ], array_filter(
            Schema::getIndexes($definition->table),
            fn (array $index): bool => (bool) $index['unique'] && ! (bool) $index['primary'],
        )));
        $foreignKeys = array_map(fn (array $foreignKey): array => [
            'columns' => array_values($foreignKey['columns']),
            'foreign_table' => $foreignKey['foreign_table'],
            'foreign_columns' => array_values($foreignKey['foreign_columns']),
        ], Schema::getForeignKeys($definition->table));
        usort($uniqueIndexes, fn (array $a, array $b): int => strcmp(CanonicalJson::encode($a), CanonicalJson::encode($b)));
        usort($foreignKeys, fn (array $a, array $b): int => strcmp(CanonicalJson::encode($a), CanonicalJson::encode($b)));
        $schema = [
            'table' => $definition->table,
            'primary_key' => $definition->primaryKeyColumns,
            'columns' => $columns,
            'unique_indexes' => $uniqueIndexes,
            'foreign_keys' => $foreignKeys,
        ];

        return [
            'sha256' => hash('sha256', CanonicalJson::encode($schema)),
            'schema' => $schema,
        ];
    }

    public function logicalType(string $type): string
    {
        $type = strtolower($type);

        return match (true) {
            str_contains($type, 'bool') => 'boolean',
            str_contains($type, 'int') => 'integer',
            str_contains($type, 'numeric'), str_contains($type, 'decimal'), str_contains($type, 'real'), str_contains($type, 'double'), str_contains($type, 'float') => 'decimal',
            str_contains($type, 'timestamp'), str_contains($type, 'datetime') => 'datetime',
            $type === 'date' => 'date',
            str_contains($type, 'json') => 'json',
            str_contains($type, 'char'), str_contains($type, 'string'), str_contains($type, 'varchar') => 'string',
            str_contains($type, 'text') => 'text',
            str_contains($type, 'blob'), str_contains($type, 'binary'), str_contains($type, 'bytea') => 'binary',
            default => $type,
        };
    }
}
