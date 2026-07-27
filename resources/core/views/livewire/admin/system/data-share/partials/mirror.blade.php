<?php

use App\Base\Database\Livewire\DataShare\Index;
use Illuminate\Support\Js;

/** @var Index $this */
$mirrorModules = collect($mirrorTables)
    ->map(fn (array $table): array => [
        'path' => (string) ($table['module_path'] ?? ''),
        'name' => (string) ($table['module_name'] ?? $table['module_path'] ?? ''),
    ])
    ->filter(fn (array $module): bool => $module['path'] !== '')
    ->unique('path')
    ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
    ->values();
$mirrorQuery = mb_strtolower(trim($mirrorSearch));
$visibleMirrorTables = collect($mirrorTables)
    ->filter(fn (array $table): bool => $mirrorModulePath === '' || ($table['module_path'] ?? '') === $mirrorModulePath)
    ->filter(function (array $table) use ($mirrorQuery): bool {
        if ($mirrorQuery === '') {
            return true;
        }

        return str_contains(mb_strtolower(implode(' ', [
            (string) ($table['table'] ?? ''),
            (string) ($table['module_name'] ?? ''),
            (string) ($table['module_path'] ?? ''),
        ])), $mirrorQuery);
    })
    ->values();
$mirrorCandidateSourceRows = $mirrorDirection === 'pull' ? 'remote_rows' : 'local_rows';
$mirrorCandidateDestinationRows = $mirrorDirection === 'pull' ? 'local_rows' : 'remote_rows';
$mirrorDirectionCandidates = in_array($mirrorDirection, ['pull', 'push'], true)
    ? $visibleMirrorTables
        ->filter(fn (array $table): bool => (bool) ($table['supported'] ?? false))
        ->filter(fn (array $table): bool => (bool) ($table['local_exists'] ?? false)
            && (bool) ($table['mirror_exists'] ?? false))
        ->filter(fn (array $table): bool => is_int($table[$mirrorCandidateSourceRows] ?? null)
            && is_int($table[$mirrorCandidateDestinationRows] ?? null)
            && $table[$mirrorCandidateSourceRows] > $table[$mirrorCandidateDestinationRows])
        ->reject(fn (array $table): bool => in_array($table['table'], $mirrorSelectedTables, true))
        ->values()
    : collect();
$mirrorSelectedCount = count($mirrorSelectedTables);
$mirrorReviewSelectionChanged = $mirrorReview !== null
    && collect($mirrorSelectedTables)->sort()->values()->all()
        !== collect((array) ($mirrorReview['_selected_tables'] ?? []))->sort()->values()->all();
$mirrorReviewItems = collect((array) ($mirrorReview['items'] ?? []))
    ->sortBy(fn (array $item): int => (array) ($item['blockers'] ?? []) === [] ? 1 : 0)
    ->values();
$mirrorReviewRequestedTables = collect((array) ($mirrorReview['requested_tables'] ?? []));
if ($mirrorReview !== null && $mirrorReviewRequestedTables->isEmpty()) {
    $mirrorReviewRequestedTables = collect((array) ($mirrorReview['_selected_tables'] ?? []));
}
$mirrorReviewRequiredTables = collect((array) ($mirrorReview['required_tables'] ?? []));
$mirrorReviewRequiredLookup = $mirrorReviewRequiredTables->flip();
$mirrorReviewRequiredBy = (array) ($mirrorReview['required_by'] ?? []);
$mirrorReviewGlobalBlockers = $mirrorReviewItems
    ->flatMap(fn (array $item): array => (array) ($item['blockers'] ?? []))
    ->filter(fn (array $blocker): bool => ($blocker['code'] ?? null) === 'foreign_key_cycle')
    ->unique(fn (array $blocker): string => implode('|', [
        (string) ($blocker['code'] ?? ''),
        (string) ($blocker['message'] ?? ''),
    ]))
    ->values();
$mirrorAvailable = (bool) ($mirrorConnectionStatus['available'] ?? false);
$mirrorProviderLabel = (string) ($mirrorConnectionStatus['provider_label'] ?? __('configured provider'));
$mirrorTransferMode = (string) ($mirrorConnectionStatus['transfer_mode'] ?? 'portable');
$mirrorActionVariant = static fn (string $action): string => match ($action) {
    'create' => 'success',
    'replace' => 'warning',
    'delete', 'blocked' => 'danger',
    default => 'info',
};
$mirrorBlockerMessage = static function (mixed $blocker): string {
    if (is_array($blocker)) {
        return (string) ($blocker['message'] ?? $blocker['reason'] ?? $blocker['code'] ?? __('Unknown blocker'));
    }

    return (string) $blocker;
};
?>

<div
    class="space-y-6"
    x-data="{
        mirrorReviewStarting: null,
        mirrorTransferStarting: false,
        async openMirrorReview(direction) {
            this.mirrorReviewStarting = direction;
            this.$wire.mirrorRunOpen = true;

            try {
                await this.$wire.reviewMirror(direction);
            } finally {
                this.mirrorReviewStarting = null;
            }
        },
        async startMirrorTransfer() {
            this.mirrorTransferStarting = true;

            try {
                await this.$wire.executeMirror();
            } finally {
                this.mirrorTransferStarting = false;
            }
        },
        async startMirrorForcePush() {
            this.mirrorTransferStarting = true;

            try {
                await this.$wire.forcePushMirror();
            } finally {
                this.mirrorTransferStarting = false;
            }
        },
    }"
    @if(! $mirrorCatalogLoaded)
        x-init="if (window.location.hash === '#mirror') { $wire.dataShareTabSelected('mirror') }"
    @endif
>
    <div class="max-w-3xl">
        <div class="flex flex-wrap items-center gap-2">
            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Mirror complete development tables') }}</h2>
            <x-ui.badge variant="warning">{{ __('Development only') }}</x-ui.badge>
        </div>
        <p class="mt-1 text-sm text-muted">
            {{ __('Mirror selected complete tables directly between Local and :provider.', ['provider' => $mirrorProviderLabel]) }}
        </p>
    </div>

    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <fieldset class="space-y-2">
            <legend class="text-sm font-medium text-ink">{{ __('Direction') }}</legend>
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-6"
                wire:key="mirror-direction-{{ $mirrorDirection !== '' ? $mirrorDirection : 'unset' }}"
            >
                <x-ui.radio
                    id="mirror-direction-pull"
                    name="mirror-direction"
                    value="pull"
                    :checked="$mirrorDirection === 'pull'"
                    wire:click="chooseMirrorDirection('pull')"
                    wire:loading.attr="disabled"
                    wire:target="chooseMirrorDirection"
                    :label="__('Pull from :provider', ['provider' => $mirrorProviderLabel])"
                />
                <x-ui.radio
                    id="mirror-direction-push"
                    name="mirror-direction"
                    value="push"
                    :checked="$mirrorDirection === 'push'"
                    wire:click="chooseMirrorDirection('push')"
                    wire:loading.attr="disabled"
                    wire:target="chooseMirrorDirection"
                    :label="__('Push to :provider', ['provider' => $mirrorProviderLabel])"
                />
            </div>
        </fieldset>

        <x-ui.button
            variant="control"
            size="sm"
            wire:click="refreshMirrorCatalog"
            wire:loading.attr="disabled"
            wire:target="refreshMirrorCatalog"
        >
            <x-icon name="heroicon-o-arrow-path" class="h-4 w-4" />
            <span wire:loading.remove wire:target="refreshMirrorCatalog">{{ __('Catalog') }}</span>
            <span wire:loading wire:target="refreshMirrorCatalog">{{ __('Checking…') }}</span>
        </x-ui.button>
    </div>

    <div
        wire:loading.flex
        wire:target="dataShareTabSelected,refreshMirrorCatalog"
        class="items-center gap-2 rounded-xl border border-border-default bg-surface-subtle px-3 py-2 text-sm text-muted"
    >
        <x-icon name="heroicon-o-arrow-path" class="h-4 w-4 text-accent motion-safe:animate-spin" />
        {{ __('Checking the saved connection and table catalog…') }}
    </div>

    @if(! $mirrorCatalogLoaded)
        <x-ui.alert variant="info">
            <p class="font-medium">{{ __('Mirror catalog has not been loaded') }}</p>
            <p class="mt-1">{{ __('Load it to test the saved provider connection and discover the union of registered Local and provider tables.') }}</p>
            <div class="mt-3">
                <x-ui.button variant="control" size="sm" wire:click="refreshMirrorCatalog">
                    {{ __('Load mirror catalog') }}
                </x-ui.button>
            </div>
        </x-ui.alert>
    @else
        {{-- Local rows are already rendered below. Remote enrichment runs as a
             separate request fired after this paints (Alpine x-init on DOM insert),
             so remote presence, counts, and freshness fill in without blocking. --}}
        @if($mirrorRemotePending)
            <div wire:key="mirror-remote-enrich" x-init="$wire.enrichMirrorRemote()" class="flex items-center gap-1.5 text-xs text-muted">
                <span class="h-2 w-2 animate-pulse rounded-full bg-status-info" aria-hidden="true"></span>
                {{ __('Local tables ready — checking :provider…', ['provider' => $mirrorProviderLabel]) }}
            </div>
        @elseif(! $mirrorAvailable)
            <x-ui.alert :variant="($mirrorConnectionStatus['configured'] ?? false) ? 'warning' : 'info'">
                <p class="font-medium">{{ __('Remote endpoint unavailable') }}</p>
                <p class="mt-1">{{ $mirrorConnectionStatus['message'] ?? __('Local tables are shown below; remote columns stay unavailable until the connection is reachable.') }}</p>
                @if($canManageSettings)
                    <div class="mt-3">
                        <x-ui.link :href="route('admin.system.data-share.settings').'#data_share_mirror'">
                            {{ __('Open mirror settings') }}
                        </x-ui.link>
                    </div>
                @endif
            </x-ui.alert>
        @else
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-muted">
                <span class="inline-flex items-center gap-1.5">
                    <span class="h-2 w-2 rounded-full bg-status-success" aria-hidden="true"></span>
                    {{ __(':provider reachable', ['provider' => $mirrorProviderLabel]) }}
                </span>
                @if($mirrorConnectionStatus['server_version'] ?? null)
                    <span>{{ __('PostgreSQL :version', ['version' => $mirrorConnectionStatus['server_version']]) }}</span>
                @endif
                <span>{{ __('Local and remote roles: development') }}</span>
                <x-ui.badge variant="info">
                    {{ $mirrorTransferMode === 'portable' ? __('Portable data mode') : __('Native PostgreSQL mode') }}
                </x-ui.badge>
            </div>
        @endif

        @if($mirrorTables === [])
            <div class="py-8 text-center">
                <p class="text-sm font-medium text-ink">{{ __('No registered mirror tables are available') }}</p>
                <p class="mt-1 text-sm text-muted">{{ __('Reconcile the Base table registry on the source and target, then refresh this catalog.') }}</p>
            </div>
        @else
            <x-ui.filter-bar>
                <x-slot name="search">
                    <x-ui.search-input
                        id="data-share-mirror-search"
                        wire:model.live.debounce.250ms="mirrorSearch"
                        :placeholder="__('Search tables…')"
                        :aria-label="__('Search tables')"
                    />
                </x-slot>
                <x-ui.select
                    id="data-share-mirror-module"
                    wire:model.live="mirrorModulePath"
                    :aria-label="__('Filter by module')"
                >
                    <option value="">{{ __('All modules') }}</option>
                    @foreach($mirrorModules as $module)
                        <option value="{{ $module['path'] }}">{{ $module['name'] }} · {{ $module['path'] }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.filter-bar>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-muted">
                    <span wire:text="mirrorSelectedTables.length">{{ $mirrorSelectedCount }}</span>
                    <span wire:show="mirrorSelectedTables.length === 1">{{ __('table selected') }}</span>
                    <span wire:show="mirrorSelectedTables.length !== 1">{{ __('tables selected') }}</span>
                    <span aria-hidden="true">·</span>
                    {{ trans_choice(':count table visible|:count tables visible', $visibleMirrorTables->count(), ['count' => $visibleMirrorTables->count()]) }}
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    @if($mirrorDirectionCandidates->isNotEmpty())
                        <x-ui.button
                            variant="ghost"
                            size="sm"
                            wire:click="selectMirrorRowCountCandidates"
                            wire:loading.attr="disabled"
                            wire:target="selectMirrorRowCountCandidates,reviewMirror,executeMirror,forcePushMirror"
                        >
                            <x-icon name="heroicon-o-squares-plus" class="h-4 w-4" />
                            {{ trans_choice(
                                'Select :count :direction candidate|Select :count :direction candidates',
                                $mirrorDirectionCandidates->count(),
                                ['count' => $mirrorDirectionCandidates->count(), 'direction' => $mirrorDirection],
                            ) }}
                        </x-ui.button>
                    @endif
                    <x-ui.button
                        variant="ghost"
                        size="sm"
                        wire:click="selectAllVisibleMirrorTables"
                        :disabled="$visibleMirrorTables->isEmpty()"
                        wire:loading.attr="disabled"
                        wire:target="executeMirror"
                    >
                        {{ trans_choice('Select all :count table|Select all :count tables', $visibleMirrorTables->count(), ['count' => $visibleMirrorTables->count()]) }}
                    </x-ui.button>
                    <x-ui.button
                        variant="ghost"
                        size="sm"
                        wire:click="clearMirrorSelection"
                        wire:bind:disabled="mirrorSelectedTables.length === 0"
                        wire:loading.attr="disabled"
                        wire:target="executeMirror"
                    >
                        {{ __('Deselect all') }}
                    </x-ui.button>
                </div>
            </div>

            <x-ui.table
                :caption="__('Development mirror table picker')"
                container="plain"
                :empty="$visibleMirrorTables->isEmpty()"
                :empty-colspan="7"
                :empty-message="__('No tables match this filter. Your explicit selection is unchanged.')"
                size="xs"
            >
                <x-slot name="head">
                    <tr>
                        <x-ui.th class="w-12"><span class="sr-only">{{ __('Select') }}</span></x-ui.th>
                        <x-ui.th>{{ __('Table') }}</x-ui.th>
                        <x-ui.th>{{ __('Module') }}</x-ui.th>
                        <x-ui.th class="text-right">{{ __('Local rows') }}</x-ui.th>
                        <x-ui.th class="text-right">{{ __('Remote rows') }}</x-ui.th>
                        <x-ui.th>{{ __('Observed') }}</x-ui.th>
                        <x-ui.th>{{ __('Freshness') }}</x-ui.th>
                    </tr>
                </x-slot>
                <x-slot name="body">
                    @foreach($visibleMirrorTables as $table)
                        @php
                            $tableBlockers = (array) ($table['blockers'] ?? []);
                        @endphp
                        <tr wire:key="mirror-table-{{ $table['table'] }}">
                            <td class="px-table-cell-x py-table-cell-y align-top">
                                <x-ui.checkbox
                                    id="data-share-mirror-table-{{ \Illuminate\Support\Str::slug($table['table']) }}"
                                    wire:model="mirrorSelectedTables"
                                    value="{{ $table['table'] }}"
                                    wire:loading.attr="disabled"
                                    wire:target="executeMirror"
                                />
                            </td>
                            <td class="px-table-cell-x py-table-cell-y align-top">
                                <label for="data-share-mirror-table-{{ \Illuminate\Support\Str::slug($table['table']) }}" class="break-all font-mono text-xs font-medium text-ink">
                                    {{ $table['table'] }}
                                </label>
                                @if(! ($table['supported'] ?? false))
                                    <div class="mt-1 space-y-0.5">
                                        @forelse($tableBlockers as $blocker)
                                            <p class="text-xs leading-5 text-status-danger">{{ $mirrorBlockerMessage($blocker) }}</p>
                                        @empty
                                            <p class="text-xs text-status-danger">{{ __('This relation cannot be mirrored.') }}</p>
                                        @endforelse
                                    </div>
                                @endif
                            </td>
                            <td class="px-table-cell-x py-table-cell-y align-top">
                                <p class="text-xs text-ink">{{ $table['module_name'] }}</p>
                                <p class="mt-0.5 break-all font-mono text-[11px] text-muted">{{ $table['module_path'] }}</p>
                            </td>
                            <td class="px-table-cell-x py-table-cell-y align-top text-right tabular-nums text-ink">
                                @if(! ($table['local_exists'] ?? false))
                                    <span class="text-xs text-muted">{{ __('Missing') }}</span>
                                @else
                                    {{ isset($table['local_rows']) ? number_format($table['local_rows']) : '—' }}
                                @endif
                            </td>
                            <td class="px-table-cell-x py-table-cell-y align-top text-right tabular-nums text-ink">
                                @if($mirrorRemotePending)
                                    <span class="text-xs text-muted">{{ __('Checking…') }}</span>
                                @elseif (($table['remote_available'] ?? true) === false)
                                    <span class="text-xs text-status-warning">{{ __('Unavailable') }}</span>
                                @elseif(! ($table['mirror_exists'] ?? false))
                                    <span class="text-xs text-muted">{{ __('Missing') }}</span>
                                @else
                                    {{ isset($table['remote_rows']) ? number_format($table['remote_rows']) : '—' }}
                                @endif
                            </td>
                            <td class="px-table-cell-x py-table-cell-y align-top text-xs text-muted">
                                {{ ($table['observed_at'] ?? null) ? \Illuminate\Support\Carbon::parse($table['observed_at'])->diffForHumans() : '—' }}
                            </td>
                            <td class="px-table-cell-x py-table-cell-y align-top">
                                @php $freshness = $table['freshness'] ?? 'unknown'; @endphp
                                <x-ui.badge :variant="match($freshness) { 'clean' => 'success', 'changed' => 'warning', default => 'default' }">
                                    {{ match($freshness) { 'clean' => __('Clean'), 'changed' => __('Changed since push'), default => __('Unknown') } }}
                                </x-ui.badge>
                            </td>
                        </tr>
                    @endforeach
                </x-slot>
            </x-ui.table>

            @error('mirrorSelectedTables')
                <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
            @enderror

            <div class="flex justify-end border-t border-border-default pt-4">
                <div class="flex flex-col gap-2 sm:flex-row">
                    <x-ui.button
                        variant="control"
                        wire:key="mirror-read-only-review-{{ $mirrorDirection !== '' ? $mirrorDirection : 'unset' }}-{{ $mirrorSelectedCount }}"
                        x-on:click="openMirrorReview({{ Js::from($mirrorDirection) }})"
                        :disabled="$mirrorDirection === '' || ! $canExecuteMirror"
                        wire:bind:disabled="mirrorSelectedTables.length === 0 || {{ $mirrorDirection === '' || ! $canExecuteMirror ? 'true' : 'false' }}"
                        wire:loading.attr="disabled"
                        wire:target="reviewMirror,executeMirror,forcePushMirror"
                    >
                        <x-icon name="carbon-review" class="h-4 w-4" />
                        <span wire:loading.remove wire:target="reviewMirror">{{ __('Read-only Review') }}</span>
                        <span wire:loading wire:target="reviewMirror">{{ __('Reviewing selected tables…') }}</span>
                    </x-ui.button>
                </div>
            </div>
        @endif
    @endif

    @if($mirrorResult)
        @php
            $mirrorResultCounts = (array) ($mirrorResult['counts'] ?? []);
            $createdCount = (int) ($mirrorResultCounts['created'] ?? $mirrorResultCounts['create'] ?? 0);
            $replacedCount = (int) ($mirrorResultCounts['replaced'] ?? $mirrorResultCounts['replace'] ?? 0);
            $deletedCount = (int) ($mirrorResultCounts['deleted'] ?? $mirrorResultCounts['delete'] ?? 0);
        @endphp
        <x-ui.alert variant="success">
            <p class="font-medium">{{ __('Development table mirror completed') }}</p>
            <p class="mt-1">{{ __('Created :created, replaced :replaced, and deleted :deleted selected table(s).', [
                'created' => $createdCount,
                'replaced' => $replacedCount,
                'deleted' => $deletedCount,
            ]) }}</p>
            @if(! empty($mirrorResult['run_id']))
                <p class="mt-2">
                    <a href="{{ route('admin.system.data-operations.index', ['run' => $mirrorResult['run_id']]) }}" class="text-sm font-medium text-accent underline">
                        {{ __('View durable run #:id in Data Operations', ['id' => $mirrorResult['run_id']]) }}
                    </a>
                </p>
            @endif
            <p class="mt-1 text-xs text-muted">{{ __('The catalog refreshes current Local and remote counts; the completed operation and its observed counts remain in the durable run.') }}</p>
        </x-ui.alert>
    @endif

    @php
        $mirrorRunTitle = match ($mirrorRunKind) {
            'review_pull' => $mirrorRunStatus === 'running' ? __('Reviewing pull from :provider', ['provider' => $mirrorProviderLabel]) : __('Review pull from :provider', ['provider' => $mirrorProviderLabel]),
            'review_push' => $mirrorRunStatus === 'running' ? __('Reviewing push to :provider', ['provider' => $mirrorProviderLabel]) : __('Review push to :provider', ['provider' => $mirrorProviderLabel]),
            'pull' => $mirrorRunStatus === 'running' ? __('Pulling from :provider', ['provider' => $mirrorProviderLabel]) : __('Pull from :provider', ['provider' => $mirrorProviderLabel]),
            'force_push' => $mirrorRunStatus === 'running' ? __('Force pushing to :provider', ['provider' => $mirrorProviderLabel]) : __('Force push to :provider', ['provider' => $mirrorProviderLabel]),
            default => $mirrorRunStatus === 'running' ? __('Pushing to :provider', ['provider' => $mirrorProviderLabel]) : __('Push to :provider', ['provider' => $mirrorProviderLabel]),
        };
        $mirrorRunBadge = match ($mirrorRunStatus) {
            'running' => ['variant' => 'info', 'label' => __('Running')],
            'ready' => ['variant' => 'success', 'label' => __('Ready to confirm')],
            'success' => ['variant' => 'success', 'label' => __('Complete')],
            'warning' => ['variant' => 'warning', 'label' => __('Warnings')],
            'error' => ['variant' => 'danger', 'label' => __('Needs action')],
            default => ['variant' => 'default', 'label' => __('Not started')],
        };
    @endphp

    <x-ui.modal wire:model="mirrorRunOpen" labelledby="mirror-run-log-title" class="max-w-5xl">
        <div class="p-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 id="mirror-run-log-title" class="text-base font-medium text-ink">
                            <span
                                x-show="mirrorReviewStarting"
                                x-text="mirrorReviewStarting === 'pull' ? @js(__('Reviewing pull from :provider', ['provider' => $mirrorProviderLabel])) : @js(__('Reviewing push to :provider', ['provider' => $mirrorProviderLabel]))"
                            ></span>
                            <span
                                x-show="mirrorTransferStarting"
                                x-text="@js($mirrorDirection === 'pull' ? __('Pulling from :provider', ['provider' => $mirrorProviderLabel]) : __('Pushing to :provider', ['provider' => $mirrorProviderLabel]))"
                            ></span>
                            <span x-show="! mirrorReviewStarting && ! mirrorTransferStarting">{{ $mirrorRunTitle }}</span>
                        </h2>
                        <x-ui.badge x-show="mirrorReviewStarting || mirrorTransferStarting" variant="info">
                            <x-icon name="heroicon-o-arrow-path" class="mr-1 h-3.5 w-3.5 motion-safe:animate-spin" />
                            <span x-show="mirrorReviewStarting">{{ __('Reviewing') }}</span>
                            <span x-show="mirrorTransferStarting">{{ __('Running') }}</span>
                        </x-ui.badge>
                        <x-ui.badge x-show="! mirrorReviewStarting && ! mirrorTransferStarting" :variant="$mirrorRunBadge['variant']">
                            @if($mirrorRunStatus === 'running')
                                <x-icon name="heroicon-o-arrow-path" class="mr-1 h-3.5 w-3.5 motion-safe:animate-spin" />
                            @endif
                            {{ $mirrorRunBadge['label'] }}
                        </x-ui.badge>
                    </div>
                    <p class="mt-1 text-xs text-muted">
                        <span x-show="mirrorReviewStarting">
                            {{ __('Comparing schemas and dependencies. Nothing changes during review; you can close this window and the review will continue.') }}
                        </span>
                        <span x-show="mirrorTransferStarting">
                            {{ __('Each table is staged, written to its destination, and verified. You can close this window; the operation will continue.') }}
                        </span>
                        <span x-show="! mirrorReviewStarting && ! mirrorTransferStarting">
                            {{ $mirrorReview !== null
                                ? ($mirrorReviewSelectionChanged
                                    ? __('The selection changed. Review the updated plan before anything moves.')
                                    : (($mirrorReview['has_blockers'] ?? false)
                                        ? __('Resolve the blockers below. No data has changed.')
                                        : __('Inspect the exact actions below, then start the transfer. No data has changed yet.')))
                                : ($mirrorRunStatus === 'running'
                                    ? __('Each table is staged, written to its destination, and verified. You can close this window; the operation will continue.')
                                    : __('The run log will remain available until you close this window.')) }}
                        </span>
                    </p>
                </div>
                <button
                    type="button"
                    x-on:click="show = false"
                    class="rounded-md text-muted hover:text-ink focus:outline-none focus:ring-2 focus:ring-accent"
                    aria-label="{{ __('Dismiss mirror activity') }}"
                >
                    <x-icon name="heroicon-o-x-mark" class="h-5 w-5" />
                </button>
            </div>

            <div
                x-show="mirrorReviewStarting || mirrorTransferStarting || {{ $mirrorReview === null ? 'true' : 'false' }}"
                x-data="{
                    scrollToEnd() {
                        this.$nextTick(() => { this.$el.scrollTop = this.$el.scrollHeight });
                    },
                    init() {
                        this.scrollToEnd();
                        this.observer = new MutationObserver(() => this.scrollToEnd());
                        this.observer.observe(this.$el, { childList: true, subtree: true, characterData: true });
                    },
                    destroy() {
                        this.observer?.disconnect();
                    },
                }"
                class="mt-3 h-72 overflow-y-auto rounded-md bg-surface-subtle px-3 py-2 font-mono text-[11px] leading-5 text-ink"
                aria-live="polite"
            >
                <div x-show="mirrorReviewStarting" class="space-y-0">
                    <div>{{ __('Opening review for the selected tables.') }}</div>
                    <div>{{ __('Comparing table presence, schemas, keys, and dependencies. Review never changes data.') }}</div>
                </div>
                <div x-show="mirrorTransferStarting" class="space-y-0">
                    <div>{{ $mirrorDirection === 'pull' ? __('Starting pull from :provider.', ['provider' => $mirrorProviderLabel]) : __('Starting push to :provider.', ['provider' => $mirrorProviderLabel]) }}</div>
                    <div>{{ __('Table-by-table staging, write, and verification progress will appear below.') }}</div>
                </div>
                <div class="space-y-0" wire:stream="mirrorRunLog">
                    @foreach($mirrorRunLog as $line)
                        <div class="{{ $this->mirrorRunLineClass($line) }}">{{ $line }}</div>
                    @endforeach
                </div>
            </div>

            @if($mirrorReview)
                <div x-show="! mirrorReviewStarting && ! mirrorTransferStarting" class="mt-4 max-h-[65vh] overflow-y-auto">
                    @if($mirrorReviewGlobalBlockers->isNotEmpty())
                        <div class="mb-3 rounded-md border border-warning/30 bg-warning-subtle px-3 py-2 text-xs leading-5 text-ink">
                            <p class="font-medium">{{ trans_choice('Selection blocker|Selection blockers', $mirrorReviewGlobalBlockers->count()) }}</p>
                            <ul class="mt-1 list-disc space-y-0.5 ps-4 text-muted">
                                @foreach($mirrorReviewGlobalBlockers as $blocker)
                                    <li>{{ $mirrorBlockerMessage($blocker) }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if($mirrorReviewRequiredTables->isNotEmpty())
                        <div class="mb-3 flex flex-wrap items-center gap-2 text-xs">
                            <x-ui.badge variant="info">{{ __('Required tables added') }}</x-ui.badge>
                            <span class="text-muted">
                                {{ __(':chosen chosen + :required required = :total tables', [
                                    'chosen' => $mirrorReviewRequestedTables->count(),
                                    'required' => $mirrorReviewRequiredTables->count(),
                                    'total' => $mirrorReviewItems->count(),
                                ]) }}
                            </span>
                        </div>
                    @endif

                    <x-ui.table :caption="__('Mirror plan, blockers first')" size="xs">
                        <x-slot name="head">
                            <tr>
                                <x-ui.th>{{ __('Table') }}</x-ui.th>
                                <x-ui.th>{{ __('Action') }}</x-ui.th>
                                <x-ui.th>{{ __('Details') }}</x-ui.th>
                            </tr>
                        </x-slot>
                        <x-slot name="body">
                            @foreach($mirrorReviewItems as $item)
                                @php
                                    $itemBlockers = collect((array) ($item['blockers'] ?? []))
                                        ->reject(fn (array $blocker): bool => ($blocker['code'] ?? null) === 'foreign_key_cycle')
                                        ->values();
                                    $itemIsRequired = $mirrorReviewRequiredLookup->has($item['table']);
                                    $itemRequiredBy = collect((array) ($mirrorReviewRequiredBy[$item['table']] ?? []));
                                @endphp
                                <tr wire:key="mirror-review-modal-{{ $item['table'] }}">
                                    <td class="px-table-cell-x py-table-cell-y align-top">
                                        <code class="font-mono text-xs text-ink">{{ $item['table'] }}</code>
                                        @if($itemIsRequired)
                                            <div class="mt-1"><x-ui.badge variant="info">{{ __('Required') }}</x-ui.badge></div>
                                        @endif
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y align-top">
                                        <x-ui.badge :variant="$mirrorActionVariant($item['action'])">{{ __(ucfirst($item['action'])) }}</x-ui.badge>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y align-top text-xs leading-5 text-muted">
                                        @if($itemRequiredBy->isNotEmpty())
                                            <div>
                                                <p class="font-medium text-ink">{{ __('Required by:') }}</p>
                                                <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                                    @foreach($itemRequiredBy as $requiredByTable)
                                                        <code class="rounded bg-surface-subtle px-1 py-0.5 font-mono text-[11px] text-ink">{{ $requiredByTable }}</code>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif

                                        @if($itemBlockers->isNotEmpty())
                                            <ul class="{{ $itemRequiredBy->isNotEmpty() ? 'mt-2' : '' }} space-y-0.5">
                                                @foreach($itemBlockers as $blocker)
                                                    <li>{{ $mirrorBlockerMessage($blocker) }}</li>
                                                @endforeach
                                            </ul>
                                        @elseif($itemRequiredBy->isEmpty())
                                            <span aria-hidden="true">&mdash;</span>
                                            <span class="sr-only">{{ __('No additional details') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </x-slot>
                    </x-ui.table>
                </div>
            @endif

            <div class="mt-4 flex flex-wrap items-center justify-end gap-3">
                <div class="flex flex-wrap items-center justify-end gap-2">
                    @if(! empty($mirrorResult['run_id']))
                        <x-ui.button
                            variant="control"
                            size="sm"
                            :href="route('admin.system.data-operations.index', ['run' => $mirrorResult['run_id']])"
                        >
                            {{ __('View durable run') }}
                        </x-ui.button>
                    @endif
                    @if($mirrorReview && ! $mirrorReviewSelectionChanged && $mirrorDirection === 'push' && ($mirrorReview['_can_force_push'] ?? false))
                        <x-ui.button
                            variant="danger"
                            size="sm"
                            x-on:click.prevent="
                                if (window.confirm({{ \Illuminate\Support\Js::from(__('Force push this exact selection? Missing or incompatible remote tables will be dropped and recreated, and their remote rows will be replaced by Local. Unselected remote tables are untouched. Local schema and data will not be changed.')) }})) {
                                    startMirrorForcePush();
                                }
                            "
                            :disabled="! $canExecuteMirror"
                            wire:loading.attr="disabled"
                            wire:target="forcePushMirror"
                        >
                            <x-icon name="heroicon-o-exclamation-triangle" class="h-4 w-4" />
                            {{ trans_choice('Force push :count table|Force push :count tables', $mirrorSelectedCount, ['count' => $mirrorSelectedCount]) }}
                        </x-ui.button>
                    @endif
                    @if($mirrorReview && $mirrorReviewSelectionChanged)
                        <x-ui.button
                            variant="primary"
                            size="sm"
                            x-on:click="openMirrorReview({{ \Illuminate\Support\Js::from($mirrorDirection) }})"
                            :disabled="$mirrorSelectedCount === 0 || ! $canExecuteMirror"
                            wire:loading.attr="disabled"
                            wire:target="reviewMirror"
                        >
                            <x-icon name="carbon-review" class="h-4 w-4" />
                            {{ __('Read-only Review') }}
                        </x-ui.button>
                    @elseif($mirrorReview && ! ($mirrorReview['has_blockers'] ?? true))
                        <x-ui.button
                            variant="primary"
                            size="sm"
                            x-on:click="startMirrorTransfer()"
                            x-bind:disabled="mirrorTransferStarting"
                            x-bind:aria-busy="mirrorTransferStarting"
                            :disabled="! $canExecuteMirror"
                            wire:loading.attr="disabled"
                            wire:target="executeMirror"
                        >
                            <x-icon x-show="! mirrorTransferStarting" name="heroicon-o-check" class="h-4 w-4" />
                            <x-icon x-cloak x-show="mirrorTransferStarting" name="heroicon-o-arrow-path" class="h-4 w-4 motion-safe:animate-spin" />
                            <span x-show="! mirrorTransferStarting">
                                {{ $mirrorDirection === 'push'
                                    ? trans_choice('Push :count table to :provider|Push :count tables to :provider', $mirrorSelectedCount, ['count' => $mirrorSelectedCount, 'provider' => $mirrorProviderLabel])
                                    : trans_choice('Pull :count table from :provider|Pull :count tables from :provider', $mirrorSelectedCount, ['count' => $mirrorSelectedCount, 'provider' => $mirrorProviderLabel]) }}
                            </span>
                            <span x-cloak x-show="mirrorTransferStarting">
                                {{ $mirrorDirection === 'push'
                                    ? __('Pushing to :provider…', ['provider' => $mirrorProviderLabel])
                                    : __('Pulling from :provider…', ['provider' => $mirrorProviderLabel]) }}
                            </span>
                        </x-ui.button>
                    @endif
                </div>
            </div>
        </div>
    </x-ui.modal>
</div>
