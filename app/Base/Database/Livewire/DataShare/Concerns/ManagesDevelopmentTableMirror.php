<?php

namespace App\Base\Database\Livewire\DataShare\Concerns;

use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorCatalogTable;
use App\Base\Database\Exceptions\DataShareMirrorException;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorManager;
use Throwable;

trait ManagesDevelopmentTableMirror
{
    private const MIRROR_CATALOG_SESSION_KEY = 'data_share.mirror.catalog_snapshot';

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

    public bool $mirrorRunOpen = false;

    /** @var list<string> */
    public array $mirrorRunLog = [];

    public string $mirrorRunStatus = 'idle';

    public string $mirrorRunKind = 'push';

    public bool $mirrorCatalogLoaded = false;

    /** True after Local rows render, until the separate remote enrichment runs. */
    public bool $mirrorRemotePending = false;

    public string $mirrorModulePath = '';

    public string $mirrorSearch = '';

    public string $mirrorDirection = '';

    private function restoreMirrorCatalogSnapshotOnMount(DataShareMirrorManager $mirror): void
    {
        $this->mirrorCatalogLoaded = $this->restoreMirrorCatalogSnapshot($mirror);
    }

    public function dataShareTabSelected(string $tab, DataShareMirrorManager $mirror): void
    {
        if ($tab === 'mirror' && ! $this->mirrorCatalogLoaded) {
            $this->loadMirrorCatalog($mirror);
        }
    }

    public function refreshMirrorCatalog(DataShareMirrorManager $mirror): void
    {
        $this->loadMirrorCatalog($mirror, force: true);
    }

    private function loadMirrorCatalog(DataShareMirrorManager $mirror, bool $force = false): void
    {
        $this->requireCapability('admin.system.data-share.view');
        $this->mirrorCatalogLoaded = true;
        $this->mirrorReview = null;

        if (! $force && $this->restoreMirrorCatalogSnapshot($mirror)) {
            $this->mirrorRemotePending = false;

            return;
        }

        session()->forget(self::MIRROR_CATALOG_SESSION_KEY);

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
            $this->storeMirrorCatalogSnapshot($mirror);
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

    public function chooseMirrorDirection(string $direction): void
    {
        $this->requireCapability('admin.system.data-share.view');

        if (! in_array($direction, ['pull', 'push'], true)) {
            return;
        }

        if ($this->mirrorDirection === $direction) {
            return;
        }

        $this->mirrorDirection = $direction;
        $this->mirrorSelectedTables = [];
        $this->resetValidation('mirrorDirection');
        $this->clearMirrorReview();
    }

    public function selectMirrorRowCountCandidates(): void
    {
        $this->requireCapability('admin.system.data-share.view');

        if (! in_array($this->mirrorDirection, ['pull', 'push'], true)) {
            $this->addError('mirrorDirection', __('Choose Pull or Push first.'));

            return;
        }

        $visible = array_fill_keys($this->visibleMirrorTableNames(), true);
        $sourceRows = $this->mirrorDirection === 'pull' ? 'remote_rows' : 'local_rows';
        $destinationRows = $this->mirrorDirection === 'pull' ? 'local_rows' : 'remote_rows';
        $candidates = collect($this->mirrorTables)
            ->filter(fn (array $table): bool => isset($visible[(string) ($table['table'] ?? '')]))
            ->filter(fn (array $table): bool => (bool) ($table['supported'] ?? false))
            ->filter(fn (array $table): bool => (bool) ($table['local_exists'] ?? false)
                && (bool) ($table['mirror_exists'] ?? false))
            ->filter(fn (array $table): bool => is_int($table[$sourceRows] ?? null)
                && is_int($table[$destinationRows] ?? null)
                && $table[$sourceRows] > $table[$destinationRows])
            ->pluck('table')
            ->filter(fn (mixed $table): bool => is_string($table) && $table !== '')
            ->reject(fn (string $table): bool => in_array($table, $this->mirrorSelectedTables, true))
            ->values()
            ->all();

        if ($candidates === []) {
            $this->setStatus(__('No additional :direction candidates are visible.', [
                'direction' => $this->mirrorDirection,
            ]), 'info');

            return;
        }

        $this->mirrorSelectedTables = array_values(array_unique([
            ...$this->mirrorSelectedTables,
            ...$candidates,
        ]));
        $this->clearMirrorReview();
        $this->setStatus(trans_choice(
            ':count :direction candidate selected.|:count :direction candidates selected.',
            count($candidates),
            ['count' => count($candidates), 'direction' => $this->mirrorDirection],
        ), 'info');
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
        $this->startMirrorReviewLog($direction);

        try {
            $review = $mirror->review(
                $direction,
                $this->mirrorSelectedTables,
                function (string $message): void {
                    $this->streamMirrorRunLine($message);
                },
            )->toArray();
            $this->mirrorDirection = $direction;
            $this->mirrorReview = $this->normalizeMirrorReview($review);
            $resolvedTables = $this->mirrorReview['selected_tables'] ?: $this->mirrorSelectedTables;
            $this->mirrorSelectedTables = $resolvedTables;
            $this->mirrorReview['_selected_tables'] = $resolvedTables;
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
            $blockedCount = (int) ($this->mirrorReview['counts']['blocked'] ?? 0);
            $requiredCount = count($this->mirrorReview['required_tables']);
            $this->finishMirrorReviewLog();
            $this->setStatus(
                ($this->mirrorReview['has_blockers'] ?? false)
                    ? trans_choice(
                        ':count selected table is blocked. No data changed. Open the review for its exact dependency.|:count selected tables are blocked. No data changed. Open the review for their exact dependencies.',
                        $blockedCount,
                        ['count' => $blockedCount],
                    )
                    : ($requiredCount > 0
                        ? trans_choice(
                            'Mirror review is ready with :count required table added. Nothing has changed yet.|Mirror review is ready with :count required tables added. Nothing has changed yet.',
                            $requiredCount,
                            ['count' => $requiredCount],
                        )
                        : __('Mirror review is ready. Nothing has changed yet.')),
                ($this->mirrorReview['has_blockers'] ?? false) ? 'warning' : 'success',
            );
        } catch (DataShareMirrorException $exception) {
            $this->mirrorReview = null;
            $this->streamMirrorRunLine((string) __('FAILED: :message', ['message' => $exception->getMessage()]));
            $this->finishMirrorRunLog('error');
            $this->setStatus($exception->getMessage(), 'danger');
        } catch (Throwable $exception) {
            $this->mirrorReview = null;
            $failure = DataShareMirrorException::unexpected('review', $exception);
            $this->streamMirrorRunLine((string) __('FAILED: :message', ['message' => $failure->getMessage()]));
            $this->finishMirrorRunLog('error');
            $this->setStatus($failure->getMessage(), 'danger');
        }
    }

    public function executeMirror(DataShareMirrorManager $mirror): void
    {
        $this->requireCapability('admin.system.data-share-mirror.execute');
        $this->extendMirrorRequestTimeLimit();
        $this->validateMirrorSelection($this->mirrorDirection);
        $this->startMirrorRunLog($this->mirrorDirection);

        if (! $this->reviewMatchesCurrentMirrorSelection()) {
            $this->streamMirrorRunLine((string) __('Warning: The current selection or direction no longer matches the review.'));
            $this->finishMirrorRunLog('warning');
            $this->setStatus(__('Review this exact table selection and direction before executing the mirror.'), 'warning');

            return;
        }

        if ($this->mirrorReview['has_blockers'] ?? true) {
            $this->streamMirrorRunLine((string) __('Warning: The reviewed operation still contains blockers. No data changed.'));
            $this->finishMirrorRunLog('warning');
            $this->setStatus(__('Resolve every listed blocker and review the selection again before executing.'), 'warning');

            return;
        }

        $runOutcome = 'success';

        try {
            $this->mirrorResult = $this->normalizeMirrorResult(
                $mirror->execute(
                    $this->mirrorDirection,
                    $this->mirrorSelectedTables,
                    (string) ($this->mirrorReview['state_token'] ?? ''),
                    function (string $line): void {
                        $this->streamMirrorRunLine($line);
                    },
                )->toArray(),
            );
            $this->mirrorReview = null;
            $this->setStatus(
                __('The :direction mirror completed for :count reviewed table(s).', [
                    'direction' => $this->mirrorDirection,
                    'count' => count($this->mirrorSelectedTables),
                ]),
                'success',
            );
            $this->streamMirrorRunLine((string) __('Transfer complete. Refreshing live Local and remote counts.'));

            $catalogError = $this->refreshMirrorCatalogAfterRun($mirror);
            if ($catalogError !== null) {
                $runOutcome = 'warning';
                $this->setStatus(
                    __('The mirror committed successfully, but the catalog could not be refreshed. :error', [
                        'error' => $catalogError,
                    ]),
                    'warning',
                );
            }
        } catch (DataShareMirrorException $exception) {
            $runOutcome = in_array($exception->reasonCode, ['stale_review', 'lock_unavailable'], true) ? 'warning' : 'error';
            $this->mirrorResult = null;
            $this->mirrorReview = null;
            $this->streamMirrorRunLine((string) ($runOutcome === 'warning'
                ? __('Warning: :message', ['message' => $exception->getMessage()])
                : __('FAILED: :message', ['message' => $exception->getMessage()])));
            $this->setStatus(
                $exception->outcomeIndeterminate
                    ? $exception->getMessage().' '.__('The commit outcome could not be confirmed. Refresh the catalog and inspect the selected tables before retrying.')
                    : $exception->getMessage(),
                in_array($exception->reasonCode, ['stale_review', 'lock_unavailable'], true) ? 'warning' : 'danger',
            );
        } catch (Throwable $exception) {
            $runOutcome = 'error';
            $this->mirrorResult = null;
            $this->mirrorReview = null;
            $failure = DataShareMirrorException::unexpected('execute', $exception, outcomeIndeterminate: true);
            $this->streamMirrorRunLine((string) __('FAILED: :message', ['message' => $failure->getMessage()]));
            $this->setStatus($failure->getMessage(), 'danger');
        }

        $this->finishMirrorRunLog($runOutcome);
    }

    public function forcePushMirror(DataShareMirrorManager $mirror): void
    {
        $this->requireCapability('admin.system.data-share-mirror.execute');
        $this->extendMirrorRequestTimeLimit();
        $this->validateMirrorSelection('push');
        $this->startMirrorRunLog('force_push');

        if (! $this->reviewMatchesCurrentMirrorSelection() || $this->mirrorDirection !== 'push') {
            $this->streamMirrorRunLine((string) __('Warning: The current selection no longer matches the reviewed force push.'));
            $this->finishMirrorRunLog('warning');
            $this->setStatus(__('Review this exact push selection before forcing it.'), 'warning');

            return;
        }

        $runOutcome = 'success';

        try {
            $this->mirrorResult = $this->normalizeMirrorResult(
                $mirror->forcePush(
                    $this->mirrorSelectedTables,
                    (string) ($this->mirrorReview['state_token'] ?? ''),
                    function (string $line): void {
                        $this->streamMirrorRunLine($line);
                    },
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
            $this->streamMirrorRunLine((string) __('Force push committed. Refreshing live Local and remote counts.'));

            $catalogError = $this->refreshMirrorCatalogAfterRun($mirror);
            if ($catalogError !== null) {
                $runOutcome = 'warning';
                $this->setStatus(
                    __('The force push committed successfully, but the catalog could not be refreshed. :error', [
                        'error' => $catalogError,
                    ]),
                    'warning',
                );
            }
        } catch (DataShareMirrorException $exception) {
            $runOutcome = in_array($exception->reasonCode, ['stale_review', 'lock_unavailable'], true) ? 'warning' : 'error';
            $this->mirrorResult = null;
            $this->mirrorReview = null;
            $this->streamMirrorRunLine((string) ($runOutcome === 'warning'
                ? __('Warning: :message', ['message' => $exception->getMessage()])
                : __('FAILED: :message', ['message' => $exception->getMessage()])));
            $this->setStatus(
                $exception->outcomeIndeterminate
                    ? $exception->getMessage().' '.__('The remote outcome could not be confirmed. Local was not changed. Refresh the catalog and inspect the selected remote tables before retrying.')
                    : $exception->getMessage(),
                in_array($exception->reasonCode, ['stale_review', 'lock_unavailable'], true) ? 'warning' : 'danger',
            );
        } catch (Throwable $exception) {
            $runOutcome = 'error';
            $this->mirrorResult = null;
            $this->mirrorReview = null;
            $failure = DataShareMirrorException::unexpected('force_push', $exception, outcomeIndeterminate: true);
            $this->streamMirrorRunLine((string) __('FAILED: :message', ['message' => $failure->getMessage()]));
            $this->setStatus($failure->getMessage(), 'danger');
        }

        $this->finishMirrorRunLog($runOutcome);
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

    private function startMirrorRunLog(string $direction): void
    {
        $this->mirrorRunOpen = true;
        $this->mirrorRunStatus = 'running';
        $this->mirrorRunKind = $direction;
        $this->mirrorRunLog = [];
        $this->stream('', replace: true, to: 'mirrorRunLog');

        $provider = (string) ($this->mirrorConnectionStatus['provider_label'] ?? __('configured provider'));
        $count = count($this->mirrorSelectedTables);
        $message = match ($direction) {
            'pull' => trans_choice(
                'Starting pull of :count reviewed table: :provider → Local.|Starting pull of :count reviewed tables: :provider → Local.',
                $count,
                ['count' => $count, 'provider' => $provider],
            ),
            'force_push' => trans_choice(
                'Starting force push of :count reviewed table: Local → :provider.|Starting force push of :count reviewed tables: Local → :provider.',
                $count,
                ['count' => $count, 'provider' => $provider],
            ),
            default => trans_choice(
                'Starting push of :count reviewed table: Local → :provider.|Starting push of :count reviewed tables: Local → :provider.',
                $count,
                ['count' => $count, 'provider' => $provider],
            ),
        };

        $this->streamMirrorRunLine((string) $message);
    }

    private function startMirrorReviewLog(string $direction): void
    {
        $this->mirrorRunOpen = true;
        $this->mirrorRunStatus = 'running';
        $this->mirrorRunKind = 'review_'.$direction;
        $this->mirrorRunLog = [];
        $this->stream('', replace: true, to: 'mirrorRunLog');

        $provider = (string) ($this->mirrorConnectionStatus['provider_label'] ?? __('configured provider'));
        $count = count($this->mirrorSelectedTables);
        $message = $direction === 'pull'
            ? trans_choice(
                'Reviewing pull of :count selected table: :provider → Local.|Reviewing pull of :count selected tables: :provider → Local.',
                $count,
                ['count' => $count, 'provider' => $provider],
            )
            : trans_choice(
                'Reviewing push of :count selected table: Local → :provider.|Reviewing push of :count selected tables: Local → :provider.',
                $count,
                ['count' => $count, 'provider' => $provider],
            );

        $this->streamMirrorRunLine((string) $message);
        $this->streamMirrorRunLine((string) __('Comparing table presence, schemas, keys, and dependencies. Review never changes data.'));
    }

    private function finishMirrorReviewLog(): void
    {
        $items = collect($this->mirrorReview['items'] ?? []);
        $blockedItems = $items->filter(fn (array $item): bool => (array) ($item['blockers'] ?? []) !== []);

        if ($blockedItems->isEmpty()) {
            $requiredCount = count((array) ($this->mirrorReview['required_tables'] ?? []));
            if ($requiredCount > 0) {
                $this->streamMirrorRunLine((string) trans_choice(
                    'Added :count required table automatically.|Added :count required tables automatically.',
                    $requiredCount,
                    ['count' => $requiredCount],
                ));
            }
            $this->streamMirrorRunLine((string) trans_choice(
                'Review ready: :count table can be confirmed. No data changed yet.|Review ready: :count tables can be confirmed. No data changed yet.',
                $items->count(),
                ['count' => $items->count()],
            ));
            $this->finishMirrorRunLog('ready');

            return;
        }

        $this->streamMirrorRunLine((string) trans_choice(
            'Warning: :count selected table is blocked. No data changed.|Warning: :count selected tables are blocked. No data changed.',
            $blockedItems->count(),
            ['count' => $blockedItems->count()],
        ));

        foreach ($blockedItems as $item) {
            foreach ((array) ($item['blockers'] ?? []) as $blocker) {
                $message = is_array($blocker)
                    ? (string) ($blocker['message'] ?? $blocker['reason'] ?? $blocker['code'] ?? __('Unknown blocker'))
                    : (string) $blocker;
                $this->streamMirrorRunLine((string) __('Warning: :table — :message', [
                    'table' => (string) ($item['table'] ?? __('Unknown table')),
                    'message' => $message,
                ]));
            }
        }

        $this->finishMirrorRunLog('warning');
    }

    private function streamMirrorRunLine(string $line): void
    {
        $this->mirrorRunLog[] = $line;
        $class = $this->mirrorRunLineClass($line);
        $classAttribute = $class === '' ? '' : ' class="'.$class.'"';
        $this->stream('<div'.$classAttribute.'>'.e($line).'</div>', to: 'mirrorRunLog');
    }

    private function finishMirrorRunLog(string $status): void
    {
        $this->mirrorRunStatus = $status;
    }

    private function refreshMirrorCatalogAfterRun(DataShareMirrorManager $mirror): ?string
    {
        try {
            $this->mirrorTables = $this->mapMirrorTables($mirror->catalog());
            $this->storeMirrorCatalogSnapshot($mirror);
            $this->streamMirrorRunLine((string) __('Completed: Catalog counts refreshed.'));

            return null;
        } catch (Throwable $exception) {
            $this->streamMirrorRunLine((string) __('Warning: The transfer committed, but the catalog count refresh failed.'));

            return DataShareMirrorException::unexpected('catalog', $exception)->getMessage();
        }
    }

    public function mirrorRunLineClass(string $line): string
    {
        return match (true) {
            str_starts_with($line, 'FAILED:') => 'text-status-danger',
            str_starts_with($line, 'Warning:') => 'text-status-warning',
            str_starts_with($line, 'Reviewed ') && str_contains($line, '— blocked') => 'text-status-warning',
            str_starts_with($line, 'Reviewed ') => 'text-status-success',
            str_starts_with($line, 'Completed:') => 'text-status-success',
            default => '',
        };
    }

    private function extendMirrorRequestTimeLimit(): void
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(max(60, min(7200, (int) config('data_share.mirror.timeout_seconds', 3600))));
        }
    }

    private function restoreMirrorCatalogSnapshot(DataShareMirrorManager $mirror): bool
    {
        $snapshot = session()->get(self::MIRROR_CATALOG_SESSION_KEY);
        if (! is_array($snapshot)
            || (int) ($snapshot['expires_at'] ?? 0) < now()->timestamp
            || ! hash_equals((string) ($snapshot['fingerprint'] ?? ''), $mirror->configurationFingerprint())
            || ! is_array($snapshot['status'] ?? null)
            || ! is_array($snapshot['tables'] ?? null)) {
            return false;
        }

        $this->mirrorConnectionStatus = $snapshot['status'];
        $this->mirrorTables = $snapshot['tables'];

        return true;
    }

    private function storeMirrorCatalogSnapshot(DataShareMirrorManager $mirror): void
    {
        session()->put(self::MIRROR_CATALOG_SESSION_KEY, [
            'fingerprint' => $mirror->configurationFingerprint(),
            'expires_at' => now()->addSeconds(max(1, (int) config('data_share.mirror.catalog_cache_seconds', 300)))->timestamp,
            'status' => $this->mirrorConnectionStatus,
            'tables' => $this->mirrorTables,
        ]);
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
        $normalizeTables = static fn (mixed $tables): array => array_values(array_unique(array_filter(
            array_map(static fn (mixed $table): string => trim((string) $table), (array) $tables),
            static fn (string $table): bool => $table !== '',
        )));
        $requiredBy = [];
        foreach ((array) ($review['required_by'] ?? $review['requiredBy'] ?? []) as $table => $dependencies) {
            if (! is_string($table) || $table === '') {
                continue;
            }

            $requiredBy[$table] = $normalizeTables($dependencies);
        }

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
            'selected_tables' => $normalizeTables($review['selected_tables'] ?? $review['selectedTables'] ?? []),
            'requested_tables' => $normalizeTables($review['requested_tables'] ?? $review['requestedTables'] ?? []),
            'required_tables' => $normalizeTables($review['required_tables'] ?? $review['requiredTables'] ?? []),
            'required_by' => $requiredBy,
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
