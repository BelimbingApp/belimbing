<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorBlocker;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorCatalogTable;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorProgress;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorReview;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorReviewItem;
use App\Base\Database\Enums\DataShareMirrorAction;
use App\Base\Database\Enums\DataShareMirrorDirection;
use App\Base\Database\Exceptions\DataShareMirrorException;
use Illuminate\Database\Connection;

class DataShareMirrorReviewer
{
    public function __construct(
        private readonly DataShareMirrorConnectionManager $connections,
        private readonly DataShareMirrorCatalog $catalog,
        private readonly DataShareMirrorDependencyInspector $dependencies,
        private readonly DataShareMirrorSchemaComparator $schemas,
    ) {}

    /** @param list<string> $tableNames */
    public function review(
        DataShareMirrorDirection $direction,
        array $tableNames,
        ?DataShareMirrorProgress $progress = null,
    ): DataShareMirrorReview {
        $progress ??= DataShareMirrorProgress::listen(null);
        $requestedTables = $this->validatedSelection($tableNames);
        $progress->report((string) trans_choice(
            'Validating :count selected table.|Validating :count selected tables.',
            count($requestedTables),
            ['count' => count($requestedTables)],
        ));
        $catalog = array_column($this->catalog->reviewCatalog($progress), null, 'table');
        $unknown = array_values(array_diff($requestedTables, array_keys($catalog)));
        if ($unknown !== []) {
            throw DataShareMirrorException::invalidSelection(__('Unknown or unregistered mirror table: :table.', ['table' => $unknown[0]]));
        }

        $progress->report((string) __('Opening source and destination connections.'));
        $portable = $this->connections->status()->transferMode === 'portable';
        $source = $this->connections->source($direction)->connection;
        $target = $this->connections->target($direction)->connection;
        $progress->report((string) __('Inspecting foreign keys and database prerequisites.'));
        $sourceForeignKeys = $this->dependencies->foreignKeys($source);
        $targetForeignKeys = $this->dependencies->foreignKeys($target);
        $targetUniqueKeys = $this->dependencies->uniqueKeys($target);
        $sourceTypes = $portable ? [] : $this->dependencies->customTypes($source);
        $targetTypes = $portable ? [] : $this->dependencies->availableCustomTypes($target);
        $sourceFunctions = $portable ? [] : $this->dependencies->defaultFunctions($source);
        $targetFunctions = $portable ? [] : $this->dependencies->availableFunctions($target);
        [$selectedTables, $requiredTables, $requiredBy] = $this->expandRequiredTables(
            $direction,
            $requestedTables,
            $catalog,
            $sourceForeignKeys,
            $targetForeignKeys,
            $targetUniqueKeys,
        );
        if ($requiredTables !== []) {
            $progress->report((string) trans_choice(
                'Added :count required table to this review.|Added :count required tables to this review.',
                count($requiredTables),
                ['count' => count($requiredTables)],
            ));
        }
        $selected = array_fill_keys($selectedTables, true);
        $portableOrder = $portable ? $this->dependencies->insertionOrder($source, $selectedTables) : $selectedTables;
        $items = [];
        $schemaFingerprints = [];
        $total = count($selectedTables);

        foreach ($selectedTables as $index => $tableName) {
            $current = $index + 1;
            $progress->report((string) __('Reviewing :current/:total: :table.', [
                'current' => $current,
                'total' => $total,
                'table' => $tableName,
            ]));
            [$item, $fingerprint] = $this->reviewTable($direction, $tableName, $catalog, $source, $target, $portable, $portableOrder, $selected, $sourceForeignKeys, $targetForeignKeys, $targetUniqueKeys, $sourceTypes, $targetTypes, $sourceFunctions, $targetFunctions);
            $items[] = $item;
            $schemaFingerprints[] = $fingerprint;
            $progress->report((string) ($item->blockers === []
                ? __('Reviewed :current/:total: :table — :action.', [
                    'current' => $current,
                    'total' => $total,
                    'table' => $tableName,
                    'action' => __(ucfirst($item->action->value)),
                ])
                : trans_choice(
                    'Reviewed :current/:total: :table — blocked (:count issue).|Reviewed :current/:total: :table — blocked (:count issues).',
                    count($item->blockers),
                    [
                        'current' => $current,
                        'total' => $total,
                        'table' => $tableName,
                        'count' => count($item->blockers),
                    ],
                )));
        }

        $counts = ['create' => 0, 'replace' => 0, 'delete' => 0, 'blocked' => 0];
        foreach ($items as $item) {
            $counts[$item->action->value]++;
        }
        $progress->report((string) __('Finalizing the dependency fingerprint.'));
        $dependencyFingerprint = $this->dependencies->fingerprint($source, $target, $selectedTables);
        $schemaFingerprint = hash('sha256', json_encode($schemaFingerprints, JSON_THROW_ON_ERROR));
        $tokenState = array_map(fn (DataShareMirrorReviewItem $item): array => $item->toArray(), $items);
        $stateToken = hash('sha256', json_encode([
            'direction' => $direction->value,
            'items' => $tokenState,
            'dependencies' => $dependencyFingerprint,
            'schemas' => $schemaFingerprint,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return new DataShareMirrorReview(
            direction: $direction,
            items: $items,
            hasBlockers: $counts['blocked'] > 0,
            counts: $counts,
            stateToken: $stateToken,
            requestedTables: $requestedTables,
            requiredTables: $requiredTables,
            requiredBy: $requiredBy,
        );
    }

    /** @return array{0: DataShareMirrorReviewItem, 1: array{source: ?string, target: ?string}} */
    private function reviewTable(DataShareMirrorDirection $direction, string $tableName, array $catalog, Connection $source, Connection $target, bool $portable, ?array $portableOrder, array $selected, array $sourceForeignKeys, array $targetForeignKeys, array $targetUniqueKeys, array $sourceTypes, array $targetTypes, array $sourceFunctions, array $targetFunctions): array
    {
        /** @var DataShareMirrorCatalogTable $table */
        $table = $catalog[$tableName];
        [$sourceExists, $targetExists] = $direction === DataShareMirrorDirection::Push ? [$table->localExists, $table->mirrorExists] : [$table->mirrorExists, $table->localExists];
        $action = match (true) {
            $sourceExists && $targetExists => DataShareMirrorAction::Replace, $sourceExists => DataShareMirrorAction::Create, $targetExists => DataShareMirrorAction::Delete, default => DataShareMirrorAction::Blocked
        };
        $blockers = array_merge(
            $table->blockers,
            $this->tableBlockers($table, $tableName, $source, $target, $portable, $portableOrder, $sourceExists, $targetExists),
            $targetExists ? $this->incomingDependencyBlockers($tableName, $selected, $targetForeignKeys) : [],
            $sourceExists ? $this->sourcePrerequisiteBlockers($direction, $tableName, $catalog, $selected, $sourceForeignKeys, $targetUniqueKeys) : [],
            $sourceExists ? $this->databaseObjectBlockers($tableName, $sourceTypes, $targetTypes, $sourceFunctions, $targetFunctions) : [],
        );
        $blockers = $this->uniqueBlockers($blockers);

        return [
            new DataShareMirrorReviewItem($tableName, $blockers === [] ? $action : DataShareMirrorAction::Blocked, $action, $blockers),
            ['source' => $source->getSchemaBuilder()->hasTable($tableName) ? $this->schemas->fingerprint($source, $tableName) : null, 'target' => $target->getSchemaBuilder()->hasTable($tableName) ? $this->schemas->fingerprint($target, $tableName) : null],
        ];
    }

    /** @return list<DataShareMirrorBlocker> */
    private function tableBlockers(DataShareMirrorCatalogTable $table, string $name, Connection $source, Connection $target, bool $portable, ?array $portableOrder, bool $sourceExists, bool $targetExists): array
    {
        $blockers = [];
        if (! $sourceExists && ! $targetExists) {
            $blockers[] = new DataShareMirrorBlocker('table_missing', __('Registered table is missing on both endpoints.'));
        }
        if ($portable && $sourceExists !== $targetExists) {
            $blockers[] = new DataShareMirrorBlocker('schema_missing_at_endpoint', __('Table must exist on both endpoints. Run matching application migrations first.'));
        }
        if ($portable && $sourceExists && $targetExists && ! $this->schemas->compatible($source, $target, $name)) {
            $blockers[] = new DataShareMirrorBlocker('schema_incompatible', __('Columns, keys, or foreign keys differ between endpoints. Align migrations first.'));
        }
        if ($portable && $sourceExists && $this->schemas->primaryKey($source, $name) === []) {
            $blockers[] = new DataShareMirrorBlocker('primary_key_required', __('A declared primary key is required for deterministic portable verification.'));
        }
        if ($portable && $portableOrder === null) {
            $blockers[] = new DataShareMirrorBlocker('foreign_key_cycle', __('The selected tables contain a foreign-key cycle. Portable mirroring requires an acyclic selection.'));
        }
        if ($sourceExists && ! $this->catalog->isMigrationAvailable($table)) {
            $blockers[] = new DataShareMirrorBlocker('module_code_missing', __('Migration source is not present in this checkout. Move module code through Git first.'));
        }

        return $blockers;
    }

    /** @return list<DataShareMirrorBlocker> */
    private function incomingDependencyBlockers(string $name, array $selected, array $foreignKeys): array
    {
        $blockers = [];
        foreach ($foreignKeys as $key) {
            if ($key['parent'] === $name && ! isset($selected[$key['child']])) {
                $blockers[] = new DataShareMirrorBlocker('incoming_foreign_key', __('Unselected table :child references :table on the destination. Select or migrate that dependency explicitly.', ['child' => $key['child'], 'table' => $name]), relatedTable: $key['child']);
            }
        }

        return $blockers;
    }

    /** @return list<DataShareMirrorBlocker> */
    private function sourcePrerequisiteBlockers(DataShareMirrorDirection $direction, string $name, array $catalog, array $selected, array $foreignKeys, array $uniqueKeys): array
    {
        $blockers = [];
        foreach ($foreignKeys as $key) {
            if ($key['child'] !== $name) {
                continue;
            }
            $parent = $key['parent'];
            $parentTable = $catalog[$parent] ?? null;
            if (isset($selected[$parent])) {
                $exists = $parentTable instanceof DataShareMirrorCatalogTable && ($direction === DataShareMirrorDirection::Push ? $parentTable->localExists : $parentTable->mirrorExists);
                if (! $exists) {
                    $blockers[] = new DataShareMirrorBlocker('selected_parent_missing', __('Selected parent :parent is missing at the source.', ['parent' => $parent]), relatedTable: $parent);
                }

                continue;
            }
            $exists = $parentTable instanceof DataShareMirrorCatalogTable && ($direction === DataShareMirrorDirection::Push ? $parentTable->mirrorExists : $parentTable->localExists);
            if (! $exists || ! isset($uniqueKeys[$parent][$key['parent_columns']])) {
                $blockers[] = new DataShareMirrorBlocker('missing_parent_prerequisite', __('Destination prerequisite :parent (:columns) is missing or incompatible.', ['parent' => $parent, 'columns' => $key['parent_columns']]), relatedTable: $parent);
            }
        }

        return $blockers;
    }

    /** @return list<DataShareMirrorBlocker> */
    private function databaseObjectBlockers(string $name, array $sourceTypes, array $targetTypes, array $sourceFunctions, array $targetFunctions): array
    {
        $blockers = [];
        foreach ($sourceTypes[$name] ?? [] as $type) {
            if (! isset($targetTypes[$type])) {
                $blockers[] = new DataShareMirrorBlocker('missing_custom_type', __('Destination type :type is missing.', ['type' => $type]));
            }
        }
        foreach ($sourceFunctions[$name] ?? [] as $function) {
            if (! isset($targetFunctions[$function])) {
                $blockers[] = new DataShareMirrorBlocker('missing_function', __('Destination function :function is missing.', ['function' => $function]));
            }
        }

        return $blockers;
    }

    /**
     * Expand safe registered foreign-key prerequisites to a fixed point so one
     * review presents the complete plan. Unsupported dependencies remain
     * blockers on their selected table instead of being smuggled into the plan.
     *
     * @param  list<string>  $requestedTables
     * @param  array<string, DataShareMirrorCatalogTable>  $catalog
     * @param  list<array{constraint: string, child: string, parent: string, parent_columns: string}>  $sourceForeignKeys
     * @param  list<array{constraint: string, child: string, parent: string, parent_columns: string}>  $targetForeignKeys
     * @param  array<string, array<string, true>>  $targetUniqueKeys
     * @return array{0: list<string>, 1: list<string>, 2: array<string, list<string>>}
     */
    private function expandRequiredTables(
        DataShareMirrorDirection $direction,
        array $requestedTables,
        array $catalog,
        array $sourceForeignKeys,
        array $targetForeignKeys,
        array $targetUniqueKeys,
    ): array {
        $requested = array_fill_keys($requestedTables, true);
        $selected = $requested;
        $requiredBy = [];

        do {
            $changed = false;
            $changed = $this->expandIncomingDependencies($targetForeignKeys, $catalog, $requested, $selected, $requiredBy) || $changed;
            $changed = $this->expandSourcePrerequisites($direction, $sourceForeignKeys, $targetUniqueKeys, $catalog, $requested, $selected, $requiredBy) || $changed;
        } while ($changed);

        $selectedTables = array_keys($selected);
        sort($selectedTables, SORT_STRING);
        $requiredTables = array_values(array_diff($selectedTables, $requestedTables));

        ksort($requiredBy, SORT_STRING);
        $requiredBy = array_map(static function (array $tables): array {
            $tables = array_keys($tables);
            sort($tables, SORT_STRING);

            return $tables;
        }, $requiredBy);

        return [$selectedTables, $requiredTables, $requiredBy];
    }

    private function expandIncomingDependencies(array $foreignKeys, array $catalog, array $requested, array &$selected, array &$requiredBy): bool
    {
        $changed = false;
        foreach ($foreignKeys as $key) {
            $parent = $key['parent'];
            $child = $key['child'];
            if (! isset($selected[$parent])) {
                continue;
            }
            if (isset($selected[$child])) {
                if (! isset($requested[$child])) {
                    $requiredBy[$child][$parent] = true;
                }

                continue;
            }
            if (! $this->isSelectableRequiredTable($catalog[$child] ?? null)) {
                continue;
            }
            $selected[$child] = true;
            $requiredBy[$child][$parent] = true;
            $changed = true;
        }

        return $changed;
    }

    private function expandSourcePrerequisites(DataShareMirrorDirection $direction, array $foreignKeys, array $uniqueKeys, array $catalog, array $requested, array &$selected, array &$requiredBy): bool
    {
        $changed = false;
        foreach ($foreignKeys as $key) {
            $child = $key['child'];
            $parent = $key['parent'];
            if (! isset($selected[$child])) {
                continue;
            }
            $childTable = $catalog[$child] ?? null;
            $childExists = $childTable instanceof DataShareMirrorCatalogTable && ($direction === DataShareMirrorDirection::Push ? $childTable->localExists : $childTable->mirrorExists);
            if (! $childExists) {
                continue;
            }
            $parentTable = $catalog[$parent] ?? null;
            $parentExists = $parentTable instanceof DataShareMirrorCatalogTable && ($direction === DataShareMirrorDirection::Push ? $parentTable->mirrorExists : $parentTable->localExists);
            if ($parentExists && isset($uniqueKeys[$parent][$key['parent_columns']])) {
                continue;
            }
            if (isset($selected[$parent])) {
                if (! isset($requested[$parent])) {
                    $requiredBy[$parent][$child] = true;
                }

                continue;
            }
            if (! $this->isSelectableRequiredTable($parentTable)) {
                continue;
            }
            $selected[$parent] = true;
            $requiredBy[$parent][$child] = true;
            $changed = true;
        }

        return $changed;
    }

    private function isSelectableRequiredTable(mixed $table): bool
    {
        return $table instanceof DataShareMirrorCatalogTable && $table->supported;
    }

    /** @param list<string> $tableNames @return list<string> */
    private function validatedSelection(array $tableNames): array
    {
        if ($tableNames === []) {
            throw DataShareMirrorException::emptySelection();
        }

        if (count($tableNames) !== count(array_unique($tableNames))) {
            throw DataShareMirrorException::invalidSelection(__('Every selected mirror table must appear exactly once.'));
        }

        foreach ($tableNames as $table) {
            if (! is_string($table) || preg_match('/^[A-Za-z_][A-Za-z0-9_$]{0,62}$/', $table) !== 1) {
                throw DataShareMirrorException::invalidSelection(__('Mirror selections must contain valid table names.'));
            }
        }

        sort($tableNames, SORT_STRING);

        return array_values($tableNames);
    }

    /** @param list<DataShareMirrorBlocker> $blockers @return list<DataShareMirrorBlocker> */
    private function uniqueBlockers(array $blockers): array
    {
        $unique = [];

        foreach ($blockers as $blocker) {
            $unique[$blocker->code.'|'.$blocker->message] = $blocker;
        }

        return array_values($unique);
    }
}
