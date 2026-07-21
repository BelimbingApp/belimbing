<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorBlocker;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorCatalogTable;
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
    public function review(DataShareMirrorDirection $direction, array $tableNames): DataShareMirrorReview
    {
        $selectedTables = $this->validatedSelection($tableNames);
        $catalog = array_column($this->catalog->catalog(), null, 'table');
        $unknown = array_values(array_diff($selectedTables, array_keys($catalog)));
        if ($unknown !== []) {
            throw DataShareMirrorException::invalidSelection(__('Unknown or unregistered mirror table: :table.', ['table' => $unknown[0]]));
        }

        $source = $this->connections->source($direction)->connection;
        $target = $this->connections->target($direction)->connection;
        $portable = $this->connections->status()->transferMode === 'portable';
        $sourceForeignKeys = $this->dependencies->foreignKeys($source);
        $targetForeignKeys = $this->dependencies->foreignKeys($target);
        $targetUniqueKeys = $this->dependencies->uniqueKeys($target);
        $sourceTypes = $portable ? [] : $this->dependencies->customTypes($source);
        $targetTypes = $portable ? [] : $this->dependencies->availableCustomTypes($target);
        $sourceFunctions = $portable ? [] : $this->dependencies->defaultFunctions($source);
        $targetFunctions = $portable ? [] : $this->dependencies->availableFunctions($target);
        $selected = array_fill_keys($selectedTables, true);
        $portableOrder = $portable ? $this->dependencies->insertionOrder($source, $selectedTables) : $selectedTables;
        $items = [];

        foreach ($selectedTables as $tableName) {
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
                    __(':table is registered but does not exist on either endpoint.', ['table' => $tableName]),
                );
            }

            if ($portable && $sourceExists !== $targetExists) {
                $blockers[] = new DataShareMirrorBlocker(
                    'schema_missing_at_endpoint',
                    __(':table must already exist on both endpoints. Run the matching application migrations before mirroring its data.', ['table' => $tableName]),
                );
            }

            if ($portable && $sourceExists && $targetExists && ! $this->schemas->compatible($source, $target, $tableName)) {
                $blockers[] = new DataShareMirrorBlocker(
                    'schema_incompatible',
                    __(':table has incompatible columns, keys, or foreign keys across the two database engines. Align migrations before mirroring data.', ['table' => $tableName]),
                );
            }

            if ($portable && $sourceExists && $this->schemas->primaryKey($source, $tableName) === []) {
                $blockers[] = new DataShareMirrorBlocker(
                    'primary_key_required',
                    __(':table needs a declared primary key for deterministic portable snapshot verification.', ['table' => $tableName]),
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
                    __('The migration source for :table is not present in this checkout. Move module code through Git first.', ['table' => $tableName]),
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
                                __(':table references selected table :parent, but that parent does not exist at the source.', [
                                    'table' => $tableName,
                                    'parent' => $parent,
                                ]),
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
                            __('Destination prerequisite :parent (:columns) required by :table is missing or incompatible.', [
                                'parent' => $parent,
                                'columns' => $foreignKey['parent_columns'],
                                'table' => $tableName,
                            ]),
                        );
                    }
                }

                foreach ($sourceTypes[$tableName] ?? [] as $type) {
                    if (! isset($targetTypes[$type])) {
                        $blockers[] = new DataShareMirrorBlocker(
                            'missing_custom_type',
                            __('Destination prerequisite type :type required by :table is missing.', ['type' => $type, 'table' => $tableName]),
                        );
                    }
                }

                foreach ($sourceFunctions[$tableName] ?? [] as $function) {
                    if (! isset($targetFunctions[$function])) {
                        $blockers[] = new DataShareMirrorBlocker(
                            'missing_function',
                            __('Destination prerequisite function :function required by :table is missing.', ['function' => $function, 'table' => $tableName]),
                        );
                    }
                }
            }

            $blockers = $this->uniqueBlockers($blockers);
            $items[] = new DataShareMirrorReviewItem(
                table: $tableName,
                action: $blockers === [] ? $intendedAction : DataShareMirrorAction::Blocked,
                intendedAction: $intendedAction,
                blockers: $blockers,
            );
        }

        $counts = ['create' => 0, 'replace' => 0, 'delete' => 0, 'blocked' => 0];
        foreach ($items as $item) {
            $counts[$item->action->value]++;
        }
        $dependencyFingerprint = $this->dependencies->fingerprint($source, $target, $selectedTables);
        $schemaFingerprint = hash('sha256', json_encode(array_map(fn (string $table): array => [
            'source' => $source->getSchemaBuilder()->hasTable($table) ? $this->schemas->fingerprint($source, $table) : null,
            'target' => $target->getSchemaBuilder()->hasTable($table) ? $this->schemas->fingerprint($target, $table) : null,
        ], $selectedTables), JSON_THROW_ON_ERROR));
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
        );
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
