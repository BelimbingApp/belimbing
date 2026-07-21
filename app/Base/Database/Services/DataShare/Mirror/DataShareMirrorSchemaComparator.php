<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\Services\DataShare\CanonicalJson;
use Illuminate\Database\Connection;

class DataShareMirrorSchemaComparator
{
    public function compatible(Connection $source, Connection $target, string $table): bool
    {
        return hash_equals($this->fingerprint($source, $table), $this->fingerprint($target, $table));
    }

    public function fingerprint(Connection $connection, string $table): string
    {
        $schema = $connection->getSchemaBuilder();
        $columns = array_map(fn (array $column): array => [
            'name' => (string) $column['name'],
            'type' => $this->portableType((string) ($column['type'] ?? $column['type_name'] ?? '')),
            'nullable' => (bool) ($column['nullable'] ?? false),
        ], $schema->getColumns($table));
        $indexes = array_map(fn (array $index): array => [
            'columns' => array_values($index['columns']),
            'unique' => (bool) ($index['unique'] ?? false),
            'primary' => (bool) ($index['primary'] ?? false),
        ], array_filter(
            $schema->getIndexes($table),
            fn (array $index): bool => (bool) ($index['unique'] ?? false) || (bool) ($index['primary'] ?? false),
        ));
        $foreignKeys = array_map(fn (array $foreignKey): array => [
            'columns' => array_values($foreignKey['columns']),
            'foreign_table' => (string) $foreignKey['foreign_table'],
            'foreign_columns' => array_values($foreignKey['foreign_columns']),
        ], $schema->getForeignKeys($table));
        usort($indexes, fn (array $left, array $right): int => strcmp(CanonicalJson::encode($left), CanonicalJson::encode($right)));
        usort($foreignKeys, fn (array $left, array $right): int => strcmp(CanonicalJson::encode($left), CanonicalJson::encode($right)));

        return hash('sha256', CanonicalJson::encode([
            'columns' => $columns,
            'indexes' => $indexes,
            'foreign_keys' => $foreignKeys,
        ]));
    }

    public function portableType(string $type): string
    {
        $type = mb_strtolower($type);

        return match (true) {
            str_contains($type, 'bool'), $type === 'tinyint(1)' => 'boolean',
            str_contains($type, 'int') => 'integer',
            str_contains($type, 'numeric'), str_contains($type, 'decimal'), str_contains($type, 'real'), str_contains($type, 'double'), str_contains($type, 'float') => 'decimal',
            str_contains($type, 'timestamp'), str_contains($type, 'datetime') => 'datetime',
            $type === 'date' => 'date',
            str_contains($type, 'blob'), str_contains($type, 'binary'), str_contains($type, 'bytea') => 'binary',
            str_contains($type, 'json'), str_contains($type, 'char'), str_contains($type, 'text'), str_contains($type, 'uuid') => 'textual',
            default => $type,
        };
    }

    /** @return list<string> */
    public function primaryKey(Connection $connection, string $table): array
    {
        foreach ($connection->getSchemaBuilder()->getIndexes($table) as $index) {
            if ((bool) ($index['primary'] ?? false)) {
                return array_values($index['columns']);
            }
        }

        return [];
    }
}
