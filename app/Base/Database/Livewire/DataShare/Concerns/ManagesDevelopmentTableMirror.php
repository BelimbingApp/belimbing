<?php

namespace App\Base\Database\Livewire\DataShare\Concerns;

use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorCatalogTable;
use App\Base\Database\Exceptions\DataShareMirrorException;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorManager;
use Throwable;

trait ManagesDevelopmentTableMirror
{
    /** @var array<string, mixed> */
    public array $mirrorConnectionStatus = [];

    /** @var list<array<string, mixed>> */
    public array $mirrorTables = [];

    /** @var list<string> */
    public array $mirrorSelectedTables = [];

    /** @var array<string, mixed>|null */
    public ?array $mirrorReview = null;

    /** @var array<string, mixed>|null */
    public ?array $mirrorResult = null;

    public bool $mirrorCatalogLoaded = false;

    /** True after Local rows render, until the separate remote enrichment runs. */
    public bool $mirrorRemotePending = false;

    public string $mirrorModulePath = '';

    public string $mirrorSearch = '';

    public string $mirrorDirection = 'push';

    private function resetMirrorCatalogOnMount(): void
    {
        // Endpoint state must never replace a fresh Local registry read. The
        // catalog loads Local-first when the tab is opened; shared endpoint cache
        // enrichment remains a separate planned concern.
        $this->mirrorCatalogLoaded = false;
    }

    public function dataShareTabSelected(string $tab, DataShareMirrorManager $mirror): void
    {
        if ($tab === 'mirror' && ! $this->mirrorCatalogLoaded) {
            $this->loadMirrorCatalog($mirror);
        }
    }

    public function refreshMirrorCatalog(DataShareMirrorManager $mirror): void
    {
        $this->loadMirrorCatalog($mirror);
    }

    private function loadMirrorCatalog(DataShareMirrorManager $mirror): void
    {
        $this->requireCapability('admin.system.data-share.view');
        $this->mirrorCatalogLoaded = true;
        $this->mirrorReview = null;

        // Local-first: render the Local registry immediately with no remote call.
        // Remote presence, counts, and freshness arrive from enrichMirrorRemote(),
        // which the view fires as a separate request after the first paint.
        try {
            $this->mirrorTables = $this->mapMirrorTables($mirror->localCatalog());
            $this->mirrorConnectionStatus = ['configured' => true, 'available' => false, 'reachable' => false, 'remote_pending' => true];
            $this->mirrorRemotePending = true;
        } catch (Throwable $exception) {
            $failure = DataShareMirrorException::unexpected('catalog', $exception);
            $this->mirrorConnectionStatus = [
                'configured' => false,
                'available' => false,
                'reachable' => false,
                'reason_code' => $failure->reasonCode,
                'message' => $failure->getMessage(),
            ];
            $this->mirrorTables = [];
            $this->mirrorRemotePending = false;
        }
    }

    /**
     * Separate post-render request that fills in remote presence, counts, and
     * freshness. A remote failure keeps the Local rows and last-known state and
     * reports the remote columns as unavailable — it never empties Local results.
     */
    public function enrichMirrorRemote(DataShareMirrorManager $mirror): void
    {
        if (! $this->mirrorRemotePending) {
            return;
        }

        $this->mirrorRemotePending = false;

        try {
            $this->mirrorConnectionStatus = $this->normalizeMirrorStatus($mirror->status()->toArray());

            if (! ($this->mirrorConnectionStatus['available'] ?? false)) {
                return; // remote unavailable — keep the Local rows already rendered
            }

            $this->mirrorTables = $this->mapMirrorTables($mirror->catalog());
        } catch (DataShareMirrorException $exception) {
            $this->mirrorConnectionStatus = [
                'configured' => true,
                'available' => false,
                'reachable' => false,
                'reason_code' => $exception->reasonCode,
                'message' => $exception->getMessage(),
            ];
        } catch (Throwable $exception) {
            $failure = DataShareMirrorException::unexpected('catalog', $exception);
            $this->mirrorConnectionStatus = [
                'configured' => false,
                'available' => false,
                'reachable' => false,
                'reason_code' => $failure->reasonCode,
                'message' => $failure->getMessage(),
            ];
        }
    }

    /**
     * @param  list<DataShareMirrorCatalogTable>  $tables
     * @return list<array<string, mixed>>
     */
    private function mapMirrorTables(array $tables): array
    {
        return collect($tables)
            ->reject(fn (DataShareMirrorCatalogTable $table): bool => $this->isPermanentlyProtectedTable($table))
            ->map(fn (object $table): array => $this->normalizeMirrorTable($table->toArray()))
            ->sortBy([
                ['module_name', 'asc'],
                ['table', 'asc'],
            ], SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    public function selectAllVisibleMirrorTables(): void
    {
        $visible = $this->visibleMirrorTableNames();
        $selected = array_fill_keys($this->mirrorSelectedTables, true);

        foreach ($visible as $table) {
            $selected[$table] = true;
        }

        $this->mirrorSelectedTables = array_keys($selected);
        $this->clearMirrorReview();
    }

    public function clearMirrorSelection(): void
    {
        $this->mirrorSelectedTables = [];
        $this->clearMirrorReview();
    }

    public function updatedMirrorSelectedTables(): void
    {
        $this->mirrorSelectedTables = array_values(array_unique(array_filter(
            array_map(static fn (mixed $table): string => trim((string) $table), $this->mirrorSelectedTables),
            static fn (string $table): bool => $table !== '',
        )));
        $this->clearMirrorReview();
    }

    public function reviewMirror(string $direction, DataShareMirrorManager $mirror): void
    {
        $this->requireCapability('admin.system.data-share-mirror.execute');
        $this->extendMirrorRequestTimeLimit();
        $this->validateMirrorSelection($direction);

        try {
            $review = $mirror->review($direction, $this->mirrorSelectedTables)->toArray();
            $this->mirrorDirection = $direction;
            $this->mirrorReview = $this->normalizeMirrorReview($review);
            $this->mirrorReview['_selected_tables'] = $this->mirrorSelectedTables;
            $this->mirrorReview['_can_force_push'] = $direction === 'push'
                && ($this->mirrorReview['has_blockers'] ?? false)
                && collect($this->mirrorReview['items'] ?? [])
                    ->flatMap(fn (array $item): array => (array) ($item['blockers'] ?? []))
                    ->every(fn (array $blocker): bool => in_array(
                        (string) ($blocker['code'] ?? ''),
                        ['schema_missing_at_endpoint', 'schema_incompatible'],
                        true,
                    ));
            $this->mirrorResult = null;
            $this->setStatus(
                ($this->mirrorReview['has_blockers'] ?? false)
                    ? __('Mirror review contains blockers. Nothing has changed.')
                    : __('Mirror review is ready. Nothing has changed yet.'),
                ($this->mirrorReview['has_blockers'] ?? false) ? 'warning' : 'success',
            );
        } catch (DataShareMirrorException $exception) {
            $this->mirrorReview = null;
            $this->setStatus($exception->getMessage(), 'danger');
        } catch (Throwable $exception) {
            $this->mirrorReview = null;
            $this->setStatus(DataShareMirrorException::unexpected('review', $exception)->getMessage(), 'danger');
        }
    }

    public function executeMirror(DataShareMirrorManager $mirror): void
    {
        $this->requireCapability('admin.system.data-share-mirror.execute');
        $this->extendMirrorRequestTimeLimit();
        $this->validateMirrorSelection($this->mirrorDirection);

        if (! $this->reviewMatchesCurrentMirrorSelection()) {
            $this->setStatus(__('Review this exact table selection and direction before executing the mirror.'), 'warning');

            return;
        }

        if ($this->mirrorReview['has_blockers'] ?? true) {
            $this->setStatus(__('Resolve every listed blocker and review the selection again before executing.'), 'warning');

            return;
        }

        try {
            $this->mirrorResult = $this->normalizeMirrorResult(
                $mirror->execute(
                    $this->mirrorDirection,
                    $this->mirrorSelectedTables,
                    (string) ($this->mirrorReview['state_token'] ?? ''),
                )->toArray(),
            );
            $this->mirrorReview = null;
            $this->setStatus(
                __('The :direction mirror completed for :count explicitly selected table(s).', [
                    'direction' => $this->mirrorDirection,
                    'count' => count($this->mirrorSelectedTables),
                ]),
                'success',
            );

            try {
                $this->mirrorTables = $this->mapMirrorTables($mirror->catalog());
            } catch (Throwable $exception) {
                $this->setStatus(
                    __('The mirror committed successfully, but the catalog could not be refreshed. :error', [
                        'error' => DataShareMirrorException::unexpected('catalog', $exception)->getMessage(),
                    ]),
                    'warning',
                );
            }
        } catch (DataShareMirrorException $exception) {
            $this->mirrorResult = null;
            $this->mirrorReview = null;
            $this->setStatus(
                $exception->outcomeIndeterminate
                    ? $exception->getMessage().' '.__('The commit outcome could not be confirmed. Refresh the catalog and inspect the selected tables before retrying.')
                    : $exception->getMessage(),
                in_array($exception->reasonCode, ['stale_review', 'lock_unavailable'], true) ? 'warning' : 'danger',
            );
        } catch (Throwable $exception) {
            $this->mirrorResult = null;
            $this->mirrorReview = null;
            $this->setStatus(DataShareMirrorException::unexpected('execute', $exception, outcomeIndeterminate: true)->getMessage(), 'danger');
        }
    }

    public function forcePushMirror(DataShareMirrorManager $mirror): void
    {
        $this->requireCapability('admin.system.data-share-mirror.execute');
        $this->extendMirrorRequestTimeLimit();
        $this->validateMirrorSelection('push');

        if (! $this->reviewMatchesCurrentMirrorSelection() || $this->mirrorDirection !== 'push') {
            $this->setStatus(__('Review this exact push selection before forcing it.'), 'warning');

            return;
        }

        try {
            $this->mirrorResult = $this->normalizeMirrorResult(
                $mirror->forcePush(
                    $this->mirrorSelectedTables,
                    (string) ($this->mirrorReview['state_token'] ?? ''),
                )->toArray(),
            );
            $this->mirrorReview = null;
            $this->setStatus(
                trans_choice(
                    'Force push completed. Local replaced :count selected remote table; Local was not changed.|Force push completed. Local replaced :count selected remote tables; Local was not changed.',
                    count($this->mirrorSelectedTables),
                    ['count' => count($this->mirrorSelectedTables)],
                ),
                'success',
            );
        } catch (DataShareMirrorException $exception) {
            $this->mirrorResult = null;
            $this->mirrorReview = null;
            $this->setStatus(
                $exception->outcomeIndeterminate
                    ? $exception->getMessage().' '.__('The remote outcome could not be confirmed. Local was not changed. Refresh the catalog and inspect the selected remote tables before retrying.')
                    : $exception->getMessage(),
                in_array($exception->reasonCode, ['stale_review', 'lock_unavailable'], true) ? 'warning' : 'danger',
            );
        } catch (Throwable $exception) {
            $this->mirrorResult = null;
            $this->mirrorReview = null;
            $this->setStatus(DataShareMirrorException::unexpected('force_push', $exception, outcomeIndeterminate: true)->getMessage(), 'danger');
        }
    }

    /**
     * Record the current Local/remote counts for the exact selection as a
     * labelled retrospective baseline (e.g. for the completed 43-table push that
     * predates the ledger). It is an observation, never presented as a push.
     */
    public function captureMirrorBaseline(DataShareMirrorManager $mirror): void
    {
        $this->requireCapability('admin.system.data-share-mirror.execute');
        $this->extendMirrorRequestTimeLimit();
        $this->validateMirrorSelection('push');

        try {
            $mirror->captureBaseline($this->mirrorSelectedTables);
            $this->setStatus(
                __('Captured a retrospective baseline observation for :count table(s). It is labelled an observation, not an original push.', [
                    'count' => count($this->mirrorSelectedTables),
                ]),
                'success',
            );

            $this->mirrorTables = $this->mapMirrorTables($mirror->catalog());
        } catch (DataShareMirrorException $exception) {
            $this->setStatus($exception->getMessage(), 'danger');
        } catch (Throwable $exception) {
            $this->setStatus(DataShareMirrorException::unexpected('capture_baseline', $exception)->getMessage(), 'danger');
        }
    }

    public function cancelMirrorReview(): void
    {
        $this->mirrorReview = null;
    }

    private function clearMirrorReview(): void
    {
        $this->mirrorReview = null;
        $this->mirrorResult = null;
    }

    private function extendMirrorRequestTimeLimit(): void
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(max(60, min(7200, (int) config('data_share.mirror.timeout_seconds', 3600))));
        }
    }

    /** @return list<string> */
    private function visibleMirrorTableNames(): array
    {
        $search = mb_strtolower(trim($this->mirrorSearch));

        return collect($this->mirrorTables)
            ->filter(fn (array $table): bool => $this->mirrorModulePath === ''
                || ($table['module_path'] ?? '') === $this->mirrorModulePath)
            ->filter(function (array $table) use ($search): bool {
                if ($search === '') {
                    return true;
                }

                return str_contains(mb_strtolower(implode(' ', [
                    (string) ($table['table'] ?? ''),
                    (string) ($table['module_name'] ?? ''),
                    (string) ($table['module_path'] ?? ''),
                ])), $search);
            })
            ->pluck('table')
            ->filter(fn (mixed $table): bool => is_string($table) && $table !== '')
            ->values()
            ->all();
    }

    private function validateMirrorSelection(string $direction): void
    {
        $this->mirrorDirection = $direction;
        $this->validate([
            'mirrorDirection' => ['required', 'in:push,pull'],
            'mirrorSelectedTables' => ['required', 'array', 'min:1'],
            'mirrorSelectedTables.*' => ['required', 'string', 'distinct'],
        ], [
            'mirrorSelectedTables.required' => __('Select at least one exact table.'),
            'mirrorSelectedTables.min' => __('Select at least one exact table.'),
        ]);
    }

    private function reviewMatchesCurrentMirrorSelection(): bool
    {
        if ($this->mirrorReview === null
            || ($this->mirrorReview['direction'] ?? null) !== $this->mirrorDirection) {
            return false;
        }

        $reviewed = $this->mirrorReview['_selected_tables'] ?? [];
        $current = $this->mirrorSelectedTables;
        sort($reviewed, SORT_STRING);
        sort($current, SORT_STRING);

        return $reviewed === $current;
    }

    /** @param array<string, mixed> $status @return array<string, mixed> */
    private function normalizeMirrorStatus(array $status): array
    {
        return [
            ...$status,
            'local_role' => $status['local_role'] ?? $status['localRole'] ?? null,
            'remote_role' => $status['remote_role'] ?? $status['remoteRole'] ?? null,
            'server_version' => $status['server_version'] ?? $status['serverVersion'] ?? null,
            'pg_dump_version' => $status['pg_dump_version'] ?? $status['pgDumpVersion'] ?? null,
            'psql_version' => $status['psql_version'] ?? $status['psqlVersion'] ?? null,
            'reason_code' => $status['reason_code'] ?? $status['reasonCode'] ?? null,
            'provider_key' => $status['provider_key'] ?? $status['providerKey'] ?? null,
            'provider_label' => $status['provider_label'] ?? $status['providerLabel'] ?? null,
            'local_driver' => $status['local_driver'] ?? $status['localDriver'] ?? null,
            'transfer_mode' => $status['transfer_mode'] ?? $status['transferMode'] ?? null,
            'initializable' => (bool) ($status['initializable'] ?? false),
        ];
    }

    /** @param array<string, mixed> $table @return array<string, mixed> */
    /**
     * Base infrastructure/runtime-state tables (cache, sessions, jobs, ...)
     * always carry a protected_table blocker — DataShareMirrorCatalog treats
     * them as permanently unmirrorable, never actionable. Excluding them here
     * only affects this display list; DataShareMirrorReviewer re-derives the
     * catalog fresh at review/execute time, so this cannot be used to smuggle
     * a protected table through selection.
     */
    private function isPermanentlyProtectedTable(DataShareMirrorCatalogTable $table): bool
    {
        foreach ($table->blockers as $blocker) {
            if ($blocker->code === 'protected_table') {
                return true;
            }
        }

        return false;
    }

    private function normalizeMirrorTable(array $table): array
    {
        return [
            ...$table,
            'table' => (string) ($table['table'] ?? ''),
            'module_name' => (string) ($table['module_name'] ?? $table['moduleName'] ?? ''),
            'module_path' => (string) ($table['module_path'] ?? $table['modulePath'] ?? ''),
            'local_exists' => (bool) ($table['local_exists'] ?? $table['localExists'] ?? false),
            'mirror_exists' => (bool) ($table['mirror_exists'] ?? $table['mirrorExists'] ?? false),
            'supported' => (bool) ($table['supported'] ?? false),
            'blockers' => array_values((array) ($table['blockers'] ?? [])),
        ];
    }

    /** @param array<string, mixed> $review @return array<string, mixed> */
    private function normalizeMirrorReview(array $review): array
    {
        return [
            ...$review,
            'has_blockers' => (bool) ($review['has_blockers'] ?? $review['hasBlockers'] ?? false),
            'items' => array_values(array_map(static function (mixed $item): array {
                $item = (array) $item;

                return [
                    ...$item,
                    'table' => (string) ($item['table'] ?? ''),
                    'action' => strtolower((string) ($item['action'] ?? 'blocked')),
                    'blockers' => array_values((array) ($item['blockers'] ?? [])),
                ];
            }, (array) ($review['items'] ?? []))),
            'counts' => (array) ($review['counts'] ?? []),
        ];
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function normalizeMirrorResult(array $result): array
    {
        return [
            ...$result,
            'counts' => (array) ($result['counts'] ?? []),
            'items' => array_values((array) ($result['items'] ?? [])),
        ];
    }
}
