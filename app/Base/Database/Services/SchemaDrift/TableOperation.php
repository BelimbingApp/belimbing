<?php

namespace App\Base\Database\Services\SchemaDrift;

/**
 * One ordered schema mutation parsed from a migration's up() path.
 *
 * Static factories keep impossible field combinations out of the replay log.
 */
final readonly class TableOperation
{
    private function __construct(
        public TableOperationKind $kind,
        public ?string $table,
        public int $line,
        public ?string $name = null,
        public ?string $renameTo = null,
        public ?DeclaredIndex $index = null,
    ) {}

    public static function createTable(string $table, int $line): self
    {
        return new self(TableOperationKind::CREATE_TABLE, $table, $line);
    }

    public static function dropTable(string $table, int $line): self
    {
        return new self(TableOperationKind::DROP_TABLE, $table, $line);
    }

    public static function renameTable(string $table, string $renameTo, int $line): self
    {
        return new self(TableOperationKind::RENAME_TABLE, $table, $line, renameTo: $renameTo);
    }

    public static function addColumn(string $table, string $column, int $line): self
    {
        return new self(TableOperationKind::ADD_COLUMN, $table, $line, name: $column);
    }

    public static function dropColumn(string $table, string $column, int $line): self
    {
        return new self(TableOperationKind::DROP_COLUMN, $table, $line, name: $column);
    }

    public static function renameColumn(string $table, string $column, string $renameTo, int $line): self
    {
        return new self(TableOperationKind::RENAME_COLUMN, $table, $line, $column, $renameTo);
    }

    public static function addIndex(string $table, DeclaredIndex $index, int $line): self
    {
        return new self(TableOperationKind::ADD_INDEX, $table, $line, index: $index);
    }

    public static function dropIndex(?string $table, DeclaredIndex $index, int $line): self
    {
        return new self(TableOperationKind::DROP_INDEX, $table, $line, index: $index);
    }

    public static function dropIndexNamed(?string $table, string $name, int $line): self
    {
        return new self(TableOperationKind::DROP_INDEX, $table, $line, name: $name);
    }

    public static function renameIndex(string $table, string $name, string $renameTo, int $line): self
    {
        return new self(TableOperationKind::RENAME_INDEX, $table, $line, $name, $renameTo);
    }
}
