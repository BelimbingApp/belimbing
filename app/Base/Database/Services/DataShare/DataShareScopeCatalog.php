<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\DTO\DataShare\DataShareReferenceDefinition;
use App\Base\Database\DTO\DataShare\DataShareScopeDefinition;
use App\Base\Database\DTO\DataShare\DataShareTableDefinition;
use App\Base\Database\Exceptions\DataShareDefinitionException;
use App\Base\Database\Models\TableRegistry;
use Illuminate\Support\Facades\Schema;

class DataShareScopeCatalog
{
    /** @return array<string, DataShareScopeDefinition> */
    public function scopes(): array
    {
        $available = array_fill_keys(TableRegistry::getAvailableTableNames(), true);
        $rows = TableRegistry::query()
            ->whereNotNull('module_path')
            ->orderBy('module_path')
            ->orderBy('table_name')
            ->get()
            ->filter(fn (TableRegistry $row): bool => isset($available[$row->table_name]))
            ->groupBy('module_path');
        $scopes = [];

        foreach ($rows as $modulePath => $tables) {
            $definitions = [];

            foreach ($tables as $table) {
                $definitions[] = $this->tableDefinition($table->table_name);
            }

            $name = (string) $modulePath;
            $moduleName = (string) ($tables->first()->module_name ?: basename($name));
            $scopes[$name] = new DataShareScopeDefinition(
                name: $name,
                label: mb_strlen($moduleName) <= 3
                    ? mb_strtoupper($moduleName)
                    : str($moduleName)->headline()->toString(),
                modulePath: $name,
                tables: $this->dependencyOrder($definitions),
            );
        }

        return $scopes;
    }

    /** @param list<string> $selectedTables */
    public function scope(string $name, array $selectedTables = []): DataShareScopeDefinition
    {
        $scope = $this->scopes()[$name]
            ?? throw DataShareDefinitionException::invalid(__('unknown export scope :scope.', ['scope' => $name]));

        if ($selectedTables === []) {
            return $scope;
        }

        $selected = array_fill_keys($selectedTables, true);
        $tables = array_values(array_filter(
            $scope->tables,
            fn (DataShareTableDefinition $table): bool => isset($selected[$table->table]),
        ));

        if ($tables === [] || count($tables) !== count(array_unique($selectedTables))) {
            throw DataShareDefinitionException::invalid(__('selected tables must belong to export scope :scope.', ['scope' => $name]));
        }

        return new DataShareScopeDefinition($scope->name, $scope->label, $scope->modulePath, $this->dependencyOrder($tables));
    }

    public function table(string $table): DataShareTableDefinition
    {
        foreach ($this->scopes() as $scope) {
            foreach ($scope->tables as $definition) {
                if ($definition->table === $table) {
                    return $definition;
                }
            }
        }

        throw DataShareDefinitionException::unclassifiedTable($table);
    }

    private function tableDefinition(string $table): DataShareTableDefinition
    {
        $primary = collect(Schema::getIndexes($table))->first(fn (array $index): bool => (bool) $index['primary']);
        $columns = collect(Schema::getColumns($table))->keyBy('name');
        $references = array_map(function (array $foreignKey) use ($columns): DataShareReferenceDefinition {
            return new DataShareReferenceDefinition(
                localColumns: array_values($foreignKey['columns']),
                targetTable: (string) $foreignKey['foreign_table'],
                targetColumns: array_values($foreignKey['foreign_columns']),
                nullable: collect($foreignKey['columns'])->every(
                    fn (string $column): bool => (bool) ($columns[$column]['nullable'] ?? false),
                ),
            );
        }, Schema::getForeignKeys($table));

        return new DataShareTableDefinition(
            table: $table,
            primaryKeyColumns: array_values($primary['columns'] ?? []),
            references: $references,
        );
    }

    /**
     * @param  list<DataShareTableDefinition>  $tables
     * @return list<DataShareTableDefinition>
     */
    private function dependencyOrder(array $tables): array
    {
        $byName = [];
        $dependencies = [];

        foreach ($tables as $table) {
            $byName[$table->table] = $table;
            $dependencies[$table->table] = [];
        }

        foreach ($tables as $table) {
            foreach ($table->references as $reference) {
                if ($reference->targetTable !== $table->table && isset($byName[$reference->targetTable])) {
                    $dependencies[$table->table][$reference->targetTable] = true;
                }
            }
        }

        $ordered = [];

        while ($dependencies !== []) {
            $ready = array_keys(array_filter($dependencies, fn (array $required): bool => $required === []));
            sort($ready, SORT_STRING);

            if ($ready === []) {
                throw DataShareDefinitionException::invalid(__('selected tables contain a foreign-key cycle that generic insert ordering cannot satisfy.'));
            }

            foreach ($ready as $name) {
                $ordered[] = $byName[$name];
                unset($dependencies[$name]);

                foreach ($dependencies as &$required) {
                    unset($required[$name]);
                }

                unset($required);
            }
        }

        return $ordered;
    }
}
