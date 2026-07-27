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
            /** @var DataShareMirrorCatalogTable $table */
            $table = $catalog[$tableName];
            [$sourceExists, $targetExists] = $direction === DataShareMirrorDirection::Push
                ? [$table->localExists, $table->mirrorExists]
                : [$table->mirrorExists, $table->localExists];
            $intendedAction = match (true) {
                $sourceExists && $targetExists => DataShareMirrorAction::Replace,
                $sourceExists => DataShareMirrorAction::Create,
                $targetExists => DataShareMirrorAction::Delete,
                default => DataShareMirrorAction::Blocked,
            };
            $blockers = $table->blockers;

            if (! $sourceExists && ! $targetExists) {
                $blockers[] = new DataShareMirrorBlocker(
                    'table_missing',
                    __('Registered table is missing on both endpoints.'),
                );
            }

            if ($portable && $sourceExists !== $targetExists) {
                $blockers[] = new DataShareMirrorBlocker(
                    'schema_missing_at_endpoint',
                    __('Table must exist on both endpoints. Run matching application migrations first.'),
                );
            }

            if ($portable && $sourceExists && $targetExists && ! $this->schemas->compatible($source, $target, $tableName)) {
                $blockers[] = new DataShareMirrorBlocker(
                    'schema_incompatible',
                    __('Columns, keys, or foreign keys differ between endpoints. Align migrations first.'),
                );
            }

            if ($portable && $sourceExists && $this->schemas->primaryKey($source, $tableName) === []) {
                $blockers[] = new DataShareMirrorBlocker(
                    'primary_key_required',
                    __('A declared primary key is required for deterministic portable verification.'),
                );
            }

            if ($portable && $portableOrder === null) {
                $blockers[] = new DataShareMirrorBlocker(
                    'foreign_key_cycle',
                    __('The selected tables contain a foreign-key cycle. Portable mirroring requires an acyclic selection.'),
                );
            }

            if ($sourceExists && ! $this->catalog->isMigrationAvailable($table)) {
                $blockers[] = new DataShareMirrorBlocker(
                    'module_code_missing',
                    __('Migration source is not present in this checkout. Move module code through Git first.'),
                );
            }

            if ($targetExists) {
                foreach ($targetForeignKeys as $foreignKey) {
                    if ($foreignKey['parent'] === $tableName && ! isset($selected[$foreignKey['child']])) {
                        $blockers[] = new DataShareMirrorBlocker(
                            'incoming_foreign_key',
                            __('Unselected table :child references :table on the destination. Select or migrate that dependency explicitly.', [
                                'child' => $foreignKey['child'],
                                'table' => $tableName,
                            ]),
                            relatedTable: $foreignKey['child'],
                        );
                    }
                }
            }

            if ($sourceExists) {
                foreach ($sourceForeignKeys as $foreignKey) {
                    if ($foreignKey['child'] !== $tableName) {
                        continue;
                    }

                    $parent = $foreignKey['parent'];
                    if (isset($selected[$parent])) {
                        $parentCatalog = $catalog[$parent] ?? null;
                        $parentSourceExists = $parentCatalog instanceof DataShareMirrorCatalogTable
                            && ($direction === DataShareMirrorDirection::Push ? $parentCatalog->localExists : $parentCatalog->mirrorExists);

                        if (! $parentSourceExists) {
                            $blockers[] = new DataShareMirrorBlocker(
                                'selected_parent_missing',
                                __('Selected parent :parent is missing at the source.', [
                                    'parent' => $parent,
                                ]),
                                relatedTable: $parent,
                            );
                        }

                        continue;
                    }

                    $parentCatalog = $catalog[$parent] ?? null;
                    $parentTargetExists = $parentCatalog instanceof DataShareMirrorCatalogTable
                        && ($direction === DataShareMirrorDirection::Push ? $parentCatalog->mirrorExists : $parentCatalog->localExists);
                    $uniqueKeyExists = isset($targetUniqueKeys[$parent][$foreignKey['parent_columns']]);

                    if (! $parentTargetExists || ! $uniqueKeyExists) {
                        $blockers[] = new DataShareMirrorBlocker(
                            'missing_parent_prerequisite',
                            __('Destination prerequisite :parent (:columns) is missing or incompatible.', [
                                'parent' => $parent,
                                'columns' => $foreignKey['parent_columns'],
                            ]),
                            relatedTable: $parent,
                        );
                    }
                }

                foreach ($sourceTypes[$tableName] ?? [] as $type) {
                    if (! isset($targetTypes[$type])) {
                        $blockers[] = new DataShareMirrorBlocker(
                            'missing_custom_type',
                            __('Destination type :type is missing.', ['type' => $type]),
                        );
                    }
                }

                foreach ($sourceFunctions[$tableName] ?? [] as $function) {
                    if (! isset($targetFunctions[$function])) {
                        $blockers[] = new DataShareMirrorBlocker(
                            'missing_function',
                            __('Destination function :function is missing.', ['function' => $function]),
                        );
                    }
                }
            }

            $blockers = $this->uniqueBlockers($blockers);
            $item = new DataShareMirrorReviewItem(
                table: $tableName,
                action: $blockers === [] ? $intendedAction : DataShareMirrorAction::Blocked,
                intendedAction: $intendedAction,
                blockers: $blockers,
            );
            $items[] = $item;
            $schemaFingerprints[] = [
                'source' => $source->getSchemaBuilder()->hasTable($tableName) ? $this->schemas->fingerprint($source, $tableName) : null,
                'target' => $target->getSchemaBuilder()->hasTable($tableName) ? $this->schemas->fingerprint($target, $tableName) : null,
            ];
            $progress->report((string) ($blockers === []
                ? __('Reviewed :current/:total: :table — :action.', [
                    'current' => $current,
                    'total' => $total,
                    'table' => $tableName,
                    'action' => __(ucfirst($item->action->value)),
                ])
                : trans_choice(
                    'Reviewed :current/:total: :table — blocked (:count issue).|Reviewed :current/:total: :table — blocked (:count issues).',
                    count($blockers),
                    [
                        'current' => $current,
                        'total' => $total,
                        'table' => $tableName,
                        'count' => count($blockers),
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

            foreach ($targetForeignKeys as $foreignKey) {
                $parent = $foreignKey['parent'];
                $child = $foreignKey['child'];

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

            foreach ($sourceForeignKeys as $foreignKey) {
                $child = $foreignKey['child'];
                $parent = $foreignKey['parent'];

                if (! isset($selected[$child])) {
                    continue;
                }

                $childCatalog = $catalog[$child] ?? null;
                $childSourceExists = $childCatalog instanceof DataShareMirrorCatalogTable
                    && ($direction === DataShareMirrorDirection::Push ? $childCatalog->localExists : $childCatalog->mirrorExists);
                if (! $childSourceExists) {
                    continue;
                }

                $parentCatalog = $catalog[$parent] ?? null;
                $parentTargetExists = $parentCatalog instanceof DataShareMirrorCatalogTable
                    && ($direction === DataShareMirrorDirection::Push ? $parentCatalog->mirrorExists : $parentCatalog->localExists);
                $uniqueKeyExists = isset($targetUniqueKeys[$parent][$foreignKey['parent_columns']]);
                if ($parentTargetExists && $uniqueKeyExists) {
                    continue;
                }

                if (isset($selected[$parent])) {
                    if (! isset($requested[$parent])) {
                        $requiredBy[$parent][$child] = true;
                    }

                    continue;
                }

                if (! $this->isSelectableRequiredTable($parentCatalog)) {
                    continue;
                }

                $selected[$parent] = true;
                $requiredBy[$parent][$child] = true;
                $changed = true;
            }
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
