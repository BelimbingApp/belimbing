<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\DTO\DataShare\DataShareReferenceDefinition;
use App\Base\Database\DTO\DataShare\DataShareScopeDefinition;
use App\Base\Database\DTO\DataShare\DataShareTableDefinition;
use App\Base\Database\Exceptions\DataShareDefinitionException;
use App\Base\Database\Models\TableRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class DataShareScopeCatalog
{
    /**
     * Inspect the complete catalog without letting one invalid scope hide valid page options.
     * Operational callers use scope(), table(), or the strict scopes() method instead.
     *
     * @return array{
     *     scopes: array<string, DataShareScopeDefinition>,
     *     rejected: list<array{name: string, label: string, message: string}>
     * }
     */
    public function discover(): array
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
        $rejected = [];

        foreach ($rows as $modulePath => $tables) {
            $name = (string) $modulePath;
            $label = $this->scopeLabel($name, $tables);

            try {
                $scopes[$name] = $this->scopeDefinition($name, $tables);
            } catch (DataShareDefinitionException $exception) {
                $rejected[] = [
                    'name' => $name,
                    'label' => $label,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return ['scopes' => $scopes, 'rejected' => $rejected];
    }

    /** @return array<string, DataShareScopeDefinition> */
    public function scopes(): array
    {
        $discovery = $this->discover();

        if ($discovery['rejected'] !== []) {
            throw new DataShareDefinitionException($discovery['rejected'][0]['message']);
        }

        return $discovery['scopes'];
    }

    /** @param list<string> $selectedTables */
    public function scope(string $name, array $selectedTables = []): DataShareScopeDefinition
    {
        $available = array_fill_keys(TableRegistry::getAvailableTableNames(), true);
        $tables = TableRegistry::query()
            ->where('module_path', $name)
            ->orderBy('table_name')
            ->get()
            ->filter(fn (TableRegistry $row): bool => isset($available[$row->table_name]));

        if ($tables->isEmpty()) {
            throw DataShareDefinitionException::invalid(__('unknown export scope :scope.', ['scope' => $name]));
        }

        $scope = $this->scopeDefinition($name, $tables);

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
        if (! in_array($table, TableRegistry::getAvailableTableNames(), true)
            || ! TableRegistry::query()->where('table_name', $table)->whereNotNull('module_path')->exists()) {
            throw DataShareDefinitionException::unclassifiedTable($table);
        }

        return $this->tableDefinition($table);
    }

    /** @param Collection<int, TableRegistry> $tables */
    private function scopeDefinition(string $name, Collection $tables): DataShareScopeDefinition
    {
        $definitions = $tables
            ->map(fn (TableRegistry $table): DataShareTableDefinition => $this->tableDefinition($table->table_name))
            ->values()
            ->all();

        return new DataShareScopeDefinition(
            name: $name,
            label: $this->scopeLabel($name, $tables),
            modulePath: $name,
            tables: $this->dependencyOrder($definitions),
        );
    }

    /** @param Collection<int, TableRegistry> $tables */
    private function scopeLabel(string $name, Collection $tables): string
    {
        $moduleName = (string) ($tables->first()?->module_name ?: basename($name));

        return mb_strlen($moduleName) <= 3
            ? mb_strtoupper($moduleName)
            : str($moduleName)->headline()->toString();
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
        $byName = array_column($tables, null, 'table');
        $dependencies = $this->dependencyMap($tables, $byName);

        $ordered = [];

        while ($dependencies !== []) {
            $ready = $this->readyTableNames($dependencies);

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

    /**
     * @param  list<DataShareTableDefinition>  $tables
     * @param  array<string, DataShareTableDefinition>  $byName
     * @return array<string, array<string, true>>
     */
    private function dependencyMap(array $tables, array $byName): array
    {
        $dependencies = array_fill_keys(array_keys($byName), []);

        foreach ($tables as $table) {
            foreach ($table->references as $reference) {
                if ($reference->targetTable !== $table->table && isset($byName[$reference->targetTable])) {
                    $dependencies[$table->table][$reference->targetTable] = true;
                }
            }
        }

        return $dependencies;
    }

    /**
     * @param  array<string, array<string, true>>  $dependencies
     * @return list<string>
     */
    private function readyTableNames(array $dependencies): array
    {
        $ready = array_keys(array_filter($dependencies, fn (array $required): bool => $required === []));
        sort($ready, SORT_STRING);

        return $ready;
    }
}
