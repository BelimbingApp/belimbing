<?php

namespace App\Base\Database\Livewire\DataShare\Concerns;

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

    public string $mirrorModulePath = '';

    public string $mirrorSearch = '';

    public string $mirrorDirection = 'push';

    public function dataShareTabSelected(string $tab, DataShareMirrorManager $mirror): void
    {
        if ($tab === 'mirror' && ! $this->mirrorCatalogLoaded) {
            $this->refreshMirrorCatalog($mirror);
        }
    }

    public function refreshMirrorCatalog(DataShareMirrorManager $mirror): void
    {
        $this->requireCapability('admin.system.data-share.view');
        $this->mirrorCatalogLoaded = true;
        $this->mirrorReview = null;

        try {
            $status = $mirror->status()->toArray();
            $this->mirrorConnectionStatus = $this->normalizeMirrorStatus($status);

            if (! ($this->mirrorConnectionStatus['available'] ?? false)) {
                $this->mirrorTables = [];

                return;
            }

            $this->mirrorTables = collect($mirror->catalog())
                ->map(fn (object $table): array => $this->normalizeMirrorTable($table->toArray()))
                ->sortBy([
                    ['module_name', 'asc'],
                    ['table', 'asc'],
                ], SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all();

        } catch (DataShareMirrorException $exception) {
            $this->mirrorConnectionStatus = [
                'configured' => true,
                'available' => false,
                'reachable' => false,
                'reason_code' => $exception->reasonCode,
                'message' => $exception->getMessage(),
            ];
            $this->mirrorTables = [];
        } catch (Throwable) {
            $this->mirrorConnectionStatus = [
                'configured' => false,
                'available' => false,
                'reachable' => false,
                'reason_code' => 'connection_check_failed',
                'message' => __('The development mirror could not be inspected. Check its saved provider and database URL.'),
            ];
            $this->mirrorTables = [];
        }
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
        $this->validateMirrorSelection($direction);

        try {
            $review = $mirror->review($direction, $this->mirrorSelectedTables)->toArray();
            $this->mirrorDirection = $direction;
            $this->mirrorReview = $this->normalizeMirrorReview($review);
            $this->mirrorReview['_selected_tables'] = $this->mirrorSelectedTables;
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
        } catch (Throwable) {
            $this->mirrorReview = null;
            $this->setStatus(__('The mirror review could not be prepared. No tables were changed.'), 'danger');
        }
    }

    public function executeMirror(DataShareMirrorManager $mirror): void
    {
        $this->requireCapability('admin.system.data-share-mirror.execute');
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
                $this->mirrorTables = collect($mirror->catalog())
                    ->map(fn (object $table): array => $this->normalizeMirrorTable($table->toArray()))
                    ->sortBy([
                        ['module_name', 'asc'],
                        ['table', 'asc'],
                    ], SORT_NATURAL | SORT_FLAG_CASE)
                    ->values()
                    ->all();
            } catch (Throwable) {
                // The mutation succeeded; the operator can refresh discovery separately.
            }
        } catch (DataShareMirrorException $exception) {
            $this->mirrorResult = null;
            $this->mirrorReview = null;
            $this->setStatus(
                $exception->outcomeIndeterminate
                    ? __('The mirror did not report a successful commit. Refresh the catalog and inspect the selected tables before retrying; the outcome may be indeterminate if the connection ended during commit.')
                    : $exception->getMessage(),
                in_array($exception->reasonCode, ['stale_review', 'lock_unavailable'], true) ? 'warning' : 'danger',
            );
        } catch (Throwable) {
            $this->mirrorResult = null;
            $this->mirrorReview = null;
            $this->setStatus(
                __('The mirror did not report a successful commit. Refresh the catalog and inspect the selected tables before retrying; the outcome may be indeterminate if the connection ended during commit.'),
                'danger',
            );
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
