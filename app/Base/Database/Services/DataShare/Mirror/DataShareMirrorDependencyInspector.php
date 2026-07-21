<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use Illuminate\Database\Connection;

class DataShareMirrorDependencyInspector
{
    /**
     * @return list<array{constraint: string, child: string, parent: string, parent_columns: string}>
     */
    public function foreignKeys(Connection $connection): array
    {
        if ($connection->getDriverName() === 'sqlite') {
            $foreignKeys = [];
            foreach ($this->tableNames($connection) as $table) {
                foreach ($connection->getSchemaBuilder()->getForeignKeys($table) as $foreignKey) {
                    $foreignKeys[] = [
                        'constraint' => (string) ($foreignKey['name'] ?? $table.'_foreign'),
                        'child' => $table,
                        'parent' => (string) $foreignKey['foreign_table'],
                        'parent_columns' => implode(',', $foreignKey['foreign_columns']),
                    ];
                }
            }

            usort($foreignKeys, fn (array $left, array $right): int => [$left['child'], $left['parent'], $left['constraint']] <=> [$right['child'], $right['parent'], $right['constraint']]);

            return $foreignKeys;
        }

        $rows = $connection->select(<<<'SQL'
            SELECT con.conname AS constraint_name,
                   child.relname AS child_table,
                   parent.relname AS parent_table,
                   (
                       SELECT string_agg(attribute.attname, ',' ORDER BY key_column.ordinality)
                       FROM unnest(con.confkey) WITH ORDINALITY AS key_column(attnum, ordinality)
                       JOIN pg_attribute attribute
                         ON attribute.attrelid = con.confrelid
                        AND attribute.attnum = key_column.attnum
                   ) AS parent_columns
            FROM pg_constraint con
            JOIN pg_class child ON child.oid = con.conrelid
            JOIN pg_namespace child_namespace ON child_namespace.oid = child.relnamespace
            JOIN pg_class parent ON parent.oid = con.confrelid
            JOIN pg_namespace parent_namespace ON parent_namespace.oid = parent.relnamespace
            WHERE con.contype = 'f'
              AND child_namespace.nspname = 'public'
              AND parent_namespace.nspname = 'public'
            ORDER BY child.relname, parent.relname, con.conname
            SQL);

        return array_map(fn (object $row): array => [
            'constraint' => (string) $row->constraint_name,
            'child' => (string) $row->child_table,
            'parent' => (string) $row->parent_table,
            'parent_columns' => (string) $row->parent_columns,
        ], $rows);
    }

    /** @return array<string, array<string, true>> */
    public function uniqueKeys(Connection $connection): array
    {
        if ($connection->getDriverName() === 'sqlite') {
            $keys = [];
            foreach ($this->tableNames($connection) as $table) {
                foreach ($connection->getSchemaBuilder()->getIndexes($table) as $index) {
                    if ((bool) ($index['unique'] ?? false)) {
                        $keys[$table][implode(',', $index['columns'])] = true;
                    }
                }
            }

            return $keys;
        }

        $rows = $connection->select(<<<'SQL'
            SELECT relation.relname AS table_name,
                   string_agg(attribute.attname, ',' ORDER BY key_column.ordinality) AS key_columns
            FROM pg_index index_definition
            JOIN pg_class relation ON relation.oid = index_definition.indrelid
            JOIN pg_namespace relation_namespace ON relation_namespace.oid = relation.relnamespace
            JOIN unnest(index_definition.indkey) WITH ORDINALITY AS key_column(attnum, ordinality)
              ON key_column.attnum > 0
             AND key_column.ordinality <= index_definition.indnkeyatts
            JOIN pg_attribute attribute
              ON attribute.attrelid = relation.oid
             AND attribute.attnum = key_column.attnum
            WHERE relation_namespace.nspname = 'public'
              AND index_definition.indisunique
              AND index_definition.indisvalid
              AND index_definition.indpred IS NULL
              AND index_definition.indexprs IS NULL
            GROUP BY relation.relname, index_definition.indexrelid
            ORDER BY relation.relname, key_columns
            SQL);
        $keys = [];

        foreach ($rows as $row) {
            $keys[(string) $row->table_name][(string) $row->key_columns] = true;
        }

        return $keys;
    }

    /** @return array<string, list<string>> */
    public function customTypes(Connection $connection): array
    {
        if ($connection->getDriverName() === 'sqlite') {
            return [];
        }

        $rows = $connection->select(<<<'SQL'
            SELECT DISTINCT relation.relname AS table_name,
                   type_namespace.nspname || '.' || data_type.typname AS prerequisite
            FROM pg_attribute attribute
            JOIN pg_class relation ON relation.oid = attribute.attrelid
            JOIN pg_namespace relation_namespace ON relation_namespace.oid = relation.relnamespace
            JOIN pg_type data_type ON data_type.oid = attribute.atttypid
            JOIN pg_namespace type_namespace ON type_namespace.oid = data_type.typnamespace
            WHERE relation_namespace.nspname = 'public'
              AND relation.relkind = 'r'
              AND attribute.attnum > 0
              AND NOT attribute.attisdropped
              AND type_namespace.nspname NOT IN ('pg_catalog', 'information_schema')
            ORDER BY relation.relname, prerequisite
            SQL);
        $types = [];

        foreach ($rows as $row) {
            $types[(string) $row->table_name][] = (string) $row->prerequisite;
        }

        return $types;
    }

    /** @return array<string, true> */
    public function availableCustomTypes(Connection $connection): array
    {
        if ($connection->getDriverName() === 'sqlite') {
            return [];
        }

        $rows = $connection->select(<<<'SQL'
            SELECT type_namespace.nspname || '.' || data_type.typname AS prerequisite
            FROM pg_type data_type
            JOIN pg_namespace type_namespace ON type_namespace.oid = data_type.typnamespace
            WHERE type_namespace.nspname NOT IN ('pg_catalog', 'information_schema')
            ORDER BY prerequisite
            SQL);

        return array_fill_keys(array_map(fn (object $row): string => (string) $row->prerequisite, $rows), true);
    }

    /** @return array<string, list<string>> */
    public function defaultFunctions(Connection $connection): array
    {
        if ($connection->getDriverName() === 'sqlite') {
            return [];
        }

        $rows = $connection->select(<<<'SQL'
            WITH table_functions AS (
                SELECT default_definition.adrelid AS relation_id, dependency.refobjid AS function_id
                FROM pg_attrdef default_definition
                JOIN pg_depend dependency
                  ON dependency.classid = 'pg_attrdef'::regclass
                 AND dependency.objid = default_definition.oid
                 AND dependency.refclassid = 'pg_proc'::regclass

                UNION

                SELECT trigger_definition.tgrelid, trigger_definition.tgfoid
                FROM pg_trigger trigger_definition
                WHERE NOT trigger_definition.tgisinternal

                UNION

                SELECT constraint_definition.conrelid, dependency.refobjid
                FROM pg_constraint constraint_definition
                JOIN pg_depend dependency
                  ON dependency.classid = 'pg_constraint'::regclass
                 AND dependency.objid = constraint_definition.oid
                 AND dependency.refclassid = 'pg_proc'::regclass
                WHERE constraint_definition.conrelid <> 0

                UNION

                SELECT policy_definition.polrelid, dependency.refobjid
                FROM pg_policy policy_definition
                JOIN pg_depend dependency
                  ON dependency.classid = 'pg_policy'::regclass
                 AND dependency.objid = policy_definition.oid
                 AND dependency.refclassid = 'pg_proc'::regclass
            )
            SELECT DISTINCT relation.relname AS table_name,
                   function_namespace.nspname || '.' || function_definition.proname || '(' ||
                       pg_get_function_identity_arguments(function_definition.oid) || ')' AS prerequisite
            FROM table_functions
            JOIN pg_class relation ON relation.oid = table_functions.relation_id
            JOIN pg_namespace relation_namespace ON relation_namespace.oid = relation.relnamespace
            JOIN pg_proc function_definition ON function_definition.oid = table_functions.function_id
            JOIN pg_namespace function_namespace ON function_namespace.oid = function_definition.pronamespace
            WHERE relation_namespace.nspname = 'public'
              AND function_namespace.nspname NOT IN ('pg_catalog', 'information_schema')
            ORDER BY relation.relname, prerequisite
            SQL);
        $functions = [];

        foreach ($rows as $row) {
            $functions[(string) $row->table_name][] = (string) $row->prerequisite;
        }

        return $functions;
    }

    /** @return array<string, true> */
    public function availableFunctions(Connection $connection): array
    {
        if ($connection->getDriverName() === 'sqlite') {
            return [];
        }

        $rows = $connection->select(<<<'SQL'
            SELECT function_namespace.nspname || '.' || function_definition.proname || '(' ||
                       pg_get_function_identity_arguments(function_definition.oid) || ')' AS prerequisite
            FROM pg_proc function_definition
            JOIN pg_namespace function_namespace ON function_namespace.oid = function_definition.pronamespace
            WHERE function_namespace.nspname NOT IN ('pg_catalog', 'information_schema')
            ORDER BY prerequisite
            SQL);

        return array_fill_keys(array_map(fn (object $row): string => (string) $row->prerequisite, $rows), true);
    }

    /** @param list<string> $selectedTables */
    public function fingerprint(Connection $source, Connection $target, array $selectedTables): string
    {
        $selected = array_fill_keys($selectedTables, true);
        $filter = fn (array $foreignKey): bool => isset($selected[$foreignKey['child']]) || isset($selected[$foreignKey['parent']]);
        $state = [
            'source_foreign_keys' => array_values(array_filter($this->foreignKeys($source), $filter)),
            'target_foreign_keys' => array_values(array_filter($this->foreignKeys($target), $filter)),
            'target_unique_keys' => array_intersect_key($this->uniqueKeys($target), $selected),
            'source_types' => array_intersect_key($this->customTypes($source), $selected),
            'source_functions' => array_intersect_key($this->defaultFunctions($source), $selected),
        ];

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param list<string> $tables @return list<string>|null */
    public function insertionOrder(Connection $connection, array $tables): ?array
    {
        $selected = array_fill_keys($tables, true);
        $children = array_fill_keys($tables, []);
        $inDegree = array_fill_keys($tables, 0);

        foreach ($this->foreignKeys($connection) as $foreignKey) {
            $child = $foreignKey['child'];
            $parent = $foreignKey['parent'];
            if (! isset($selected[$child], $selected[$parent])) {
                continue;
            }

            if ($child === $parent) {
                return null;
            }

            $children[$parent][] = $child;
            $inDegree[$child]++;
        }

        $queue = array_keys(array_filter($inDegree, fn (int $degree): bool => $degree === 0));
        sort($queue, SORT_STRING);
        $order = [];

        while ($queue !== []) {
            $table = array_shift($queue);
            $order[] = $table;
            foreach (array_unique($children[$table]) as $child) {
                $inDegree[$child]--;
                if ($inDegree[$child] === 0) {
                    $queue[] = $child;
                    sort($queue, SORT_STRING);
                }
            }
        }

        return count($order) === count($tables) ? $order : null;
    }

    /** @return list<string> */
    private function tableNames(Connection $connection): array
    {
        return array_values(array_map(
            fn (object $row): string => (string) $row->name,
            $connection->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"),
        ));
    }
}
