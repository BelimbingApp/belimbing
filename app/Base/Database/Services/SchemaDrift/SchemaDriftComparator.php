<?php

namespace App\Base\Database\Services\SchemaDrift;

use Illuminate\Database\Schema\Builder;

final class SchemaDriftComparator
{
    /**
     * Compare only the source contract this detector can prove: declared table,
     * column, and index presence. Extra tables remain Database Residue.
     *
     * @return list<SchemaDriftFinding>
     */
    public function compare(DeclaredSchema $declared, Builder $schema): array
    {
        $findings = [];

        foreach ($declared->tables as $table) {
            if (! $schema->hasTable($table['name'])) {
                $findings[] = $this->finding(
                    SchemaDriftFindingKind::MISSING_TABLE,
                    $table,
                    $table['name'],
                );

                continue;
            }

            $findings = [
                ...$findings,
                ...$this->compareColumns($table, $schema),
                ...$this->compareIndexes($table, $schema),
            ];
        }

        usort($findings, fn (SchemaDriftFinding $left, SchemaDriftFinding $right): int => [
            strtolower($left->table),
            $left->kind->value,
            strtolower($left->object),
            $left->migration,
            $left->line,
        ] <=> [
            strtolower($right->table),
            $right->kind->value,
            strtolower($right->object),
            $right->migration,
            $right->line,
        ]);

        return $findings;
    }

    /**
     * @param  array{name: string, migration: string, line: int, columns: array<string, array{name: string, migration: string, line: int}>, indexes: array<string, mixed>}  $table
     * @return list<SchemaDriftFinding>
     */
    private function compareColumns(array $table, Builder $schema): array
    {
        $findings = [];

        $actualColumns = array_fill_keys(array_map(
            strtolower(...),
            $schema->getColumnListing($table['name']),
        ), true);

        foreach ($table['columns'] as $key => $column) {
            if (! isset($actualColumns[$key])) {
                $findings[] = new SchemaDriftFinding(
                    SchemaDriftFindingKind::MISSING_COLUMN,
                    $table['name'],
                    $column['name'],
                    $column['migration'],
                    $column['line'],
                );
            }
        }

        foreach (array_keys($actualColumns) as $column) {
            if (! isset($table['columns'][$column])) {
                $findings[] = $this->finding(
                    SchemaDriftFindingKind::UNEXPECTED_COLUMN,
                    $table,
                    $column,
                );
            }
        }

        return $findings;
    }

    /**
     * @param  array{name: string, migration: string, line: int, columns: array<string, mixed>, indexes: array<string, array{index: DeclaredIndex, migration: string, line: int}>}  $table
     * @return list<SchemaDriftFinding>
     */
    private function compareIndexes(array $table, Builder $schema): array
    {
        $findings = [];

        $actualIndexes = $this->actualIndexes($schema, $table['name']);
        $actualBySignature = [];
        $actualByName = [];
        foreach ($actualIndexes as $actualIndex) {
            $actualBySignature[$actualIndex->signature()] = true;
            if ($actualIndex->name !== null) {
                $actualByName[strtolower($actualIndex->name)] = true;
            }
        }

        $declaredSignatures = [];
        $declaredNames = [];
        foreach ($table['indexes'] as $index) {
            $declared = $index['index'];
            $present = $declared->compareByName
                ? $declared->name !== null && isset($actualByName[strtolower($declared->name)])
                : isset($actualBySignature[$declared->signature()]);

            if ($declared->compareByName && $declared->name !== null) {
                $declaredNames[strtolower($declared->name)] = true;
            } else {
                $declaredSignatures[$declared->signature()] = true;
            }

            if (! $present) {
                $findings[] = new SchemaDriftFinding(
                    SchemaDriftFindingKind::MISSING_INDEX,
                    $table['name'],
                    $this->describeIndex($declared),
                    $index['migration'],
                    $index['line'],
                );
            }
        }

        foreach ($actualIndexes as $index) {
            if (($index->type === DeclaredIndexType::UNIQUE || $index->type === DeclaredIndexType::PRIMARY)
                && ! isset($declaredSignatures[$index->signature()])
                && ($index->name === null || ! isset($declaredNames[strtolower($index->name)]))) {
                $findings[] = $this->finding(
                    SchemaDriftFindingKind::UNEXPECTED_UNIQUE_INDEX,
                    $table,
                    $this->describeIndex($index),
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<DeclaredIndex>
     */
    private function actualIndexes(Builder $schema, string $table): array
    {
        $indexes = [];

        foreach ($schema->getIndexes($table) as $index) {
            $columns = array_values(array_filter(
                $index['columns'] ?? [],
                fn (mixed $column): bool => is_string($column) && $column !== '',
            ));
            if ($columns === []) {
                continue;
            }

            $type = match (true) {
                (bool) ($index['primary'] ?? false) => DeclaredIndexType::PRIMARY,
                (bool) ($index['unique'] ?? false) => DeclaredIndexType::UNIQUE,
                default => DeclaredIndexType::INDEX,
            };
            $declared = new DeclaredIndex($columns, $type, isset($index['name']) ? (string) $index['name'] : null);
            $indexes[] = $declared;
        }

        return $indexes;
    }

    /**
     * @param  array{name: string, migration: string, line: int, columns: array<string, mixed>, indexes: array<string, mixed>}  $table
     */
    private function finding(SchemaDriftFindingKind $kind, array $table, string $object): SchemaDriftFinding
    {
        return new SchemaDriftFinding(
            $kind,
            $table['name'],
            $object,
            $table['migration'],
            $table['line'],
        );
    }

    private function describeIndex(DeclaredIndex $index): string
    {
        if ($index->compareByName && $index->name !== null) {
            return 'named('.$index->name.')';
        }

        return sprintf('%s(%s)', $index->type->value, implode(',', $index->columns));
    }
}
