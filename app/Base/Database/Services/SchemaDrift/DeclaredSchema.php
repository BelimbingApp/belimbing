<?php

namespace App\Base\Database\Services\SchemaDrift;

/**
 * Source-declared table, column, and index presence after replaying migration
 * up() operations in Laravel migration order.
 */
final readonly class DeclaredSchema
{
    /**
     * @param  array<string, array{
     *     name: string,
     *     migration: string,
     *     line: int,
     *     columns: array<string, array{name: string, migration: string, line: int}>,
     *     indexes: array<string, array{index: DeclaredIndex, migration: string, line: int}>
     * }>  $tables
     * @param  list<array{migration: string, line: int, reason: string}>  $unreadable
     */
    private function __construct(
        public array $tables,
        public array $unreadable,
    ) {}

    /**
     * @param  list<ParsedMigration>  $migrations
     */
    public static function fromMigrations(array $migrations): self
    {
        /** @var array<string, array{name: string, migration: string, line: int, incomplete: bool, columns: array<string, array{name: string, migration: string, line: int}>, indexes: array<string, array{index: DeclaredIndex, migration: string, line: int}>}> $tables */
        $tables = [];
        $unreadable = [];

        foreach ($migrations as $migration) {
            foreach ($migration->unreadable as $construct) {
                $unreadable[] = [
                    'migration' => $migration->path,
                    'line' => $construct['line'],
                    'reason' => $construct['reason'],
                ];
            }

            foreach ($migration->operations as $operation) {
                self::apply($tables, $operation, $migration->path);
            }
        }

        foreach ($tables as $key => $table) {
            if ($table['incomplete']) {
                $unreadable[] = [
                    'migration' => $table['migration'],
                    'line' => $table['line'],
                    'reason' => sprintf('Table [%s] is altered but has no source-resolvable create or rename origin.', $table['name']),
                ];
                unset($tables[$key]);

                continue;
            }

            unset($tables[$key]['incomplete']);
            ksort($tables[$key]['columns']);
            ksort($tables[$key]['indexes']);
        }

        ksort($tables);

        return new self($tables, $unreadable);
    }

    /**
     * @param  array<string, array{name: string, migration: string, line: int, incomplete: bool, columns: array<string, array{name: string, migration: string, line: int}>, indexes: array<string, array{index: DeclaredIndex, migration: string, line: int}>}>  $tables
     */
    private static function apply(array &$tables, TableOperation $operation, string $migration): void
    {
        $tableKey = $operation->table === null ? null : strtolower($operation->table);

        switch ($operation->kind) {
            case TableOperationKind::CREATE_TABLE:
                self::createTable($tables, $operation, $migration);
                break;
            case TableOperationKind::DROP_TABLE:
                if ($tableKey !== null) {
                    self::dropTable($tables, $tableKey);
                }
                break;
            case TableOperationKind::RENAME_TABLE:
                self::renameTable($tables, $operation, $migration);
                break;
            case TableOperationKind::ADD_COLUMN:
                self::addColumn($tables, $operation, $migration);
                break;
            case TableOperationKind::DROP_COLUMN:
                self::dropColumn($tables, $operation, $migration);
                break;
            case TableOperationKind::RENAME_COLUMN:
                self::renameColumn($tables, $operation, $migration);
                break;
            case TableOperationKind::ADD_INDEX:
                self::addIndex($tables, $operation, $migration);
                break;
            case TableOperationKind::DROP_INDEX:
                self::dropIndex($tables, $operation);
                break;
            case TableOperationKind::RENAME_INDEX:
                self::renameIndex($tables, $operation);
                break;
        }
    }

    /**
     * @param  array<string, array{name: string, migration: string, line: int, incomplete: bool, columns: array<string, array{name: string, migration: string, line: int}>, indexes: array<string, array{index: DeclaredIndex, migration: string, line: int}>}>  $tables
     */
    private static function createTable(array &$tables, TableOperation $operation, string $migration): void
    {
        if ($operation->table === null) {
            return;
        }

        $tables[strtolower($operation->table)] = [
            'name' => $operation->table,
            'migration' => $migration,
            'line' => $operation->line,
            'incomplete' => false,
            'columns' => [],
            'indexes' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $tables
     */
    private static function dropTable(array &$tables, string $tableKey): void
    {
        unset($tables[$tableKey]);
    }

    /**
     * @param  array<string, array{name: string, migration: string, line: int, incomplete: bool, columns: array<string, array{name: string, migration: string, line: int}>, indexes: array<string, array{index: DeclaredIndex, migration: string, line: int}>}>  $tables
     */
    private static function renameTable(array &$tables, TableOperation $operation, string $migration): void
    {
        if ($operation->table === null || $operation->renameTo === null) {
            return;
        }

        $from = strtolower($operation->table);
        $to = strtolower($operation->renameTo);
        $table = $tables[$from] ?? [
            'name' => $operation->renameTo,
            'migration' => $migration,
            'line' => $operation->line,
            'incomplete' => true,
            'columns' => [],
            'indexes' => [],
        ];

        unset($tables[$from]);
        $table['name'] = $operation->renameTo;
        $tables[$to] = $table;
    }

    /**
     * @param  array<string, array{name: string, migration: string, line: int, incomplete: bool, columns: array<string, array{name: string, migration: string, line: int}>, indexes: array<string, array{index: DeclaredIndex, migration: string, line: int}>}>  $tables
     */
    private static function addColumn(array &$tables, TableOperation $operation, string $migration): void
    {
        if ($operation->table === null || $operation->name === null) {
            return;
        }

        $table = &self::tableState($tables, $operation, $migration);
        $table['columns'][strtolower($operation->name)] = [
            'name' => $operation->name,
            'migration' => $migration,
            'line' => $operation->line,
        ];
    }

    /**
     * @param  array<string, array{name: string, migration: string, line: int, incomplete: bool, columns: array<string, array{name: string, migration: string, line: int}>, indexes: array<string, array{index: DeclaredIndex, migration: string, line: int}>}>  $tables
     */
    private static function dropColumn(array &$tables, TableOperation $operation, string $migration): void
    {
        if ($operation->table === null || $operation->name === null) {
            return;
        }

        $table = &self::tableState($tables, $operation, $migration);
        $columnKey = strtolower($operation->name);
        unset($table['columns'][$columnKey]);

        foreach ($table['indexes'] as $signature => $declared) {
            if (in_array($columnKey, array_map(strtolower(...), $declared['index']->columns), true)) {
                unset($table['indexes'][$signature]);
            }
        }
    }

    /**
     * @param  array<string, array{name: string, migration: string, line: int, incomplete: bool, columns: array<string, array{name: string, migration: string, line: int}>, indexes: array<string, array{index: DeclaredIndex, migration: string, line: int}>}>  $tables
     */
    private static function renameColumn(array &$tables, TableOperation $operation, string $migration): void
    {
        if ($operation->table === null || $operation->name === null || $operation->renameTo === null) {
            return;
        }

        $table = &self::tableState($tables, $operation, $migration);
        $from = strtolower($operation->name);
        $to = strtolower($operation->renameTo);
        $column = $table['columns'][$from] ?? [
            'name' => $operation->name,
            'migration' => $migration,
            'line' => $operation->line,
        ];
        unset($table['columns'][$from]);
        $column['name'] = $operation->renameTo;
        $column['migration'] = $migration;
        $column['line'] = $operation->line;
        $table['columns'][$to] = $column;

        $indexes = [];
        foreach ($table['indexes'] as $declared) {
            $index = $declared['index']->withRenamedColumn($operation->name, $operation->renameTo);
            $declared['index'] = $index;
            $indexes[$index->signature()] = $declared;
        }
        $table['indexes'] = $indexes;
    }

    /**
     * @param  array<string, array{name: string, migration: string, line: int, incomplete: bool, columns: array<string, array{name: string, migration: string, line: int}>, indexes: array<string, array{index: DeclaredIndex, migration: string, line: int}>}>  $tables
     */
    private static function addIndex(array &$tables, TableOperation $operation, string $migration): void
    {
        if ($operation->table === null || $operation->index === null) {
            return;
        }

        $table = &self::tableState($tables, $operation, $migration);
        $index = $operation->index->withResolvedName($table['name']);
        $table['indexes'][$index->signature()] = [
            'index' => $index,
            'migration' => $migration,
            'line' => $operation->line,
        ];
    }

    /**
     * @param  array<string, array{name: string, migration: string, line: int, incomplete: bool, columns: array<string, array{name: string, migration: string, line: int}>, indexes: array<string, array{index: DeclaredIndex, migration: string, line: int}>}>  $tables
     */
    private static function dropIndex(array &$tables, TableOperation $operation): void
    {
        $candidateTables = $operation->table === null
            ? array_keys($tables)
            : [strtolower($operation->table)];

        foreach ($candidateTables as $tableKey) {
            if (! isset($tables[$tableKey])) {
                continue;
            }

            if ($operation->index !== null) {
                unset($tables[$tableKey]['indexes'][$operation->index->signature()]);
            }

            if ($operation->name !== null) {
                foreach ($tables[$tableKey]['indexes'] as $signature => $declared) {
                    if (strcasecmp($declared['index']->resolvedName($tables[$tableKey]['name']), $operation->name) === 0) {
                        unset($tables[$tableKey]['indexes'][$signature]);
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, array{name: string, migration: string, line: int, incomplete: bool, columns: array<string, array{name: string, migration: string, line: int}>, indexes: array<string, array{index: DeclaredIndex, migration: string, line: int}>}>  $tables
     */
    private static function renameIndex(array &$tables, TableOperation $operation): void
    {
        if ($operation->table === null || $operation->name === null || $operation->renameTo === null) {
            return;
        }

        $tableKey = strtolower($operation->table);
        if (! isset($tables[$tableKey])) {
            return;
        }

        foreach ($tables[$tableKey]['indexes'] as &$declared) {
            if (strcasecmp($declared['index']->resolvedName($tables[$tableKey]['name']), $operation->name) === 0) {
                $declared['index'] = $declared['index']->withName($operation->renameTo);
            }
        }
        unset($declared);
    }

    /**
     * @param  array<string, array{name: string, migration: string, line: int, incomplete: bool, columns: array<string, array{name: string, migration: string, line: int}>, indexes: array<string, array{index: DeclaredIndex, migration: string, line: int}>}>  $tables
     * @return array{name: string, migration: string, line: int, incomplete: bool, columns: array<string, array{name: string, migration: string, line: int}>, indexes: array<string, array{index: DeclaredIndex, migration: string, line: int}>}
     */
    private static function &tableState(array &$tables, TableOperation $operation, string $migration): array
    {
        $tableName = (string) $operation->table;
        $tableKey = strtolower($tableName);

        if (! isset($tables[$tableKey])) {
            $tables[$tableKey] = [
                'name' => $tableName,
                'migration' => $migration,
                'line' => $operation->line,
                'incomplete' => true,
                'columns' => [],
                'indexes' => [],
            ];
        }

        return $tables[$tableKey];
    }
}
