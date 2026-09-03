<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\DTO\DataShare\DataShareTableDefinition;
use App\Base\Database\Exceptions\DataShareDefinitionException;

/**
 * What an operator needs to know about each column before deciding whether
 * to redact it in a transfer package (#530).
 *
 * The rule is: every column is listed and redactable; the name pattern only
 * marks a column as *suggested* and says why. Nothing here decides for the
 * operator except the one case no use case survives — a primary-key column
 * cannot be redacted, because the row would lose its identity at the
 * destination and match nothing or the wrong thing.
 *
 * Everything else is a warning, sized to what the destination will do:
 *
 *  - a NOT NULL column redacted → those rows plan as conflicts and cannot be
 *    restored (measured in blb-people-connector#53);
 *  - a foreign-key column redacted, nullable or not → reference resolution
 *    treats the row as unresolvable, or a nullable link silently drops;
 *  - a unique-index column redacted → identity-by-unique-key restores are
 *    lost and nulls may collide with the index;
 *  - a plain nullable column redacted → the values simply do not travel.
 *    Said out loud rather than silently: silence is what #530 exists to end.
 */
class DataShareRedactionAdvisor
{
    public const LEVEL_REFUSED = 'refused';

    public const LEVEL_UNRESTORABLE = 'unrestorable';

    public const LEVEL_REFERENCE = 'reference';

    public const LEVEL_UNIQUE = 'unique';

    public const LEVEL_QUIET = 'quiet';

    public function __construct(private readonly DataShareSchemaFingerprint $schemaFingerprint) {}

    /**
     * Normalise and validate an operator's redaction map against the scope:
     * unknown tables and columns are refused, and so is a primary-key column.
     *
     * @param  list<DataShareTableDefinition>  $tables
     * @param  array<string, list<string>>  $redactions
     * @return array<string, list<string>> table → sorted column list, only for tables with redactions
     */
    public function normalize(array $tables, array $redactions): array
    {
        $byName = [];

        foreach ($tables as $table) {
            $byName[$table->table] = $table;
        }

        $normalized = [];

        foreach ($redactions as $tableName => $columns) {
            if (! is_string($tableName) || ! isset($byName[$tableName])) {
                throw DataShareDefinitionException::invalid(__('redactions name the table :table, which is not in this share.', ['table' => (string) $tableName]));
            }

            $columns = array_values(array_unique(array_filter(is_array($columns) ? $columns : [], is_string(...))));

            if ($columns === []) {
                continue;
            }

            $schema = $this->schemaFingerprint->forTable($byName[$tableName])['schema'];
            $known = array_column($schema['columns'], 'name');

            foreach ($columns as $column) {
                if (! in_array($column, $known, true)) {
                    throw DataShareDefinitionException::invalid(__('redactions name the column :column on :table, which does not exist.', ['column' => $column, 'table' => $tableName]));
                }

                if (in_array($column, $schema['primary_key'], true)) {
                    throw DataShareDefinitionException::invalid(__(':column is part of the primary key of :table and cannot be redacted: the row would lose its identity at the destination.', ['column' => $column, 'table' => $tableName]));
                }
            }

            sort($columns, SORT_STRING);
            $normalized[$tableName] = $columns;
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * One advisory per column of the table, in schema order.
     *
     * @param  list<string>  $redacted
     * @param  array<string, mixed>|null  $schema  the schema block already computed for the payload;
     *                                             omitted, the table is introspected here
     * @return list<array{
     *     name: string, type: string, nullable: bool, roles: list<string>,
     *     suggested: bool, redacted: bool, level: string|null, message: string|null
     * }>
     */
    public function advise(DataShareTableDefinition $table, array $redacted, int $records, ?array $schema = null): array
    {
        $schema ??= $this->schemaFingerprint->forTable($table)['schema'];
        $foreignKeyColumns = [];

        foreach ($schema['foreign_keys'] as $foreignKey) {
            foreach ($foreignKey['columns'] as $column) {
                $foreignKeyColumns[$column] = true;
            }
        }

        $uniqueColumns = [];

        foreach ($schema['unique_indexes'] as $index) {
            foreach ($index['columns'] as $column) {
                $uniqueColumns[$column] = true;
            }
        }

        $advisories = [];

        foreach ($schema['columns'] as $column) {
            $name = (string) $column['name'];
            $roles = [];

            if (in_array($name, $schema['primary_key'], true)) {
                $roles[] = 'primary_key';
            }

            if (isset($foreignKeyColumns[$name])) {
                $roles[] = 'foreign_key';
            }

            if (isset($uniqueColumns[$name])) {
                $roles[] = 'unique';
            }

            $isRedacted = in_array($name, $redacted, true);
            [$level, $message] = $this->consequence($table->table, $name, (bool) $column['nullable'], $roles, $isRedacted, $records);

            $advisories[] = [
                'name' => $name,
                'type' => (string) $column['type'],
                'nullable' => (bool) $column['nullable'],
                'roles' => $roles,
                'suggested' => ColumnRedactor::looksSensitive($name),
                'redacted' => $isRedacted,
                'level' => $level,
                'message' => $message,
            ];
        }

        return $advisories;
    }

    /**
     * @param  list<string>  $roles
     * @return array{0: string|null, 1: string|null}
     */
    private function consequence(string $table, string $column, bool $nullable, array $roles, bool $redacted, int $records): array
    {
        if (in_array('primary_key', $roles, true)) {
            return [self::LEVEL_REFUSED, __(':column is part of the primary key and cannot be redacted.', ['column' => $column])];
        }

        if (! $redacted) {
            return [null, null];
        }

        if (in_array('foreign_key', $roles, true)) {
            return [self::LEVEL_REFERENCE, $nullable
                ? __('Redacting :column drops the reference silently: rows restore with no link where one existed.', ['column' => $column])
                : __('Redacting :column makes the reference unresolvable: :records rows of :table will plan as conflicts.', ['column' => $column, 'records' => $records, 'table' => $table])];
        }

        if (! $nullable) {
            return [self::LEVEL_UNRESTORABLE, __('Redacting :column makes :records rows of :table unrestorable at the destination: they will plan as conflicts.', ['column' => $column, 'records' => $records, 'table' => $table])];
        }

        if (in_array('unique', $roles, true)) {
            return [self::LEVEL_UNIQUE, __('Redacting :column removes a unique identity: rows can no longer be matched by it, and nulls may collide with the index.', ['column' => $column])];
        }

        return [self::LEVEL_QUIET, __('Values of :column will not travel; the destination receives null.', ['column' => $column])];
    }
}
