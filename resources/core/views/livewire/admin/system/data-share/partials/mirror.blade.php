<?php

use App\Base\Database\Livewire\DataShare\Index;

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
$mirrorSelectedCount = count($mirrorSelectedTables);
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
    @if(! $mirrorCatalogLoaded)
        x-init="if (window.location.hash === '#mirror') { $wire.dataShareTabSelected('mirror') }"
    @endif
>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="max-w-3xl">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Mirror complete development tables') }}</h2>
                <x-ui.badge variant="warning">{{ __('Development only') }}</x-ui.badge>
            </div>
            <p class="mt-1 text-sm leading-6 text-muted">
                {{ __('Select exact tables whose data one side should make authoritative. Push uses Local as the source; Pull uses :provider as the source. Schema stays migration-owned, and review never changes data.', ['provider' => $mirrorProviderLabel]) }}
            </p>
        </div>

        <x-ui.button
            variant="control"
            size="sm"
            wire:click="refreshMirrorCatalog"
            wire:loading.attr="disabled"
            wire:target="refreshMirrorCatalog"
        >
            <x-icon name="heroicon-o-arrow-path" class="h-4 w-4" />
            <span wire:loading.remove wire:target="refreshMirrorCatalog">{{ __('Refresh catalog') }}</span>
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
    @elseif(! $mirrorAvailable)
        <x-ui.alert :variant="($mirrorConnectionStatus['configured'] ?? false) ? 'warning' : 'info'">
            <p class="font-medium">{{ __('Development mirror unavailable') }}</p>
            <p class="mt-1">{{ $mirrorConnectionStatus['message'] ?? __('Save and test a development PostgreSQL connection before mirroring tables.') }}</p>
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

        @if($mirrorTables === [])
            <div class="py-8 text-center">
                <p class="text-sm font-medium text-ink">{{ __('No registered mirror tables are available') }}</p>
                <p class="mt-1 text-sm text-muted">{{ __('Reconcile the Base table registry on the source and target, then refresh this catalog.') }}</p>
            </div>
        @else
            <div class="grid gap-4 lg:grid-cols-[minmax(16rem,0.65fr)_minmax(12rem,0.35fr)]">
                <x-ui.search-input
                    id="data-share-mirror-search"
                    wire:model.live.debounce.250ms="mirrorSearch"
                    :placeholder="__('Search tables…')"
                    :aria-label="__('Search tables')"
                />

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
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-muted">
                    {{ trans_choice(':count table selected|:count tables selected', $mirrorSelectedCount, ['count' => $mirrorSelectedCount]) }}
                    <span aria-hidden="true">·</span>
                    {{ trans_choice(':count table visible|:count tables visible', $visibleMirrorTables->count(), ['count' => $visibleMirrorTables->count()]) }}
                </p>
                <div class="flex flex-wrap items-center gap-2">
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
                        :disabled="$mirrorSelectedCount === 0"
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
                :empty-colspan="5"
                :empty-message="__('No tables match this filter. Your explicit selection is unchanged.')"
                size="xs"
            >
                <x-slot name="head">
                    <tr>
                        <x-ui.th class="w-12"><span class="sr-only">{{ __('Select') }}</span></x-ui.th>
                        <x-ui.th>{{ __('Table') }}</x-ui.th>
                        <x-ui.th>{{ __('Module') }}</x-ui.th>
                        <x-ui.th>{{ __('Local') }}</x-ui.th>
                        <x-ui.th>{{ $mirrorProviderLabel }}</x-ui.th>
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
                                    wire:model.live="mirrorSelectedTables"
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
                            <td class="px-table-cell-x py-table-cell-y align-top">
                                <x-ui.badge :variant="($table['local_exists'] ?? false) ? 'success' : 'default'">
                                    {{ ($table['local_exists'] ?? false) ? __('Present') : __('Missing') }}
                                </x-ui.badge>
                            </td>
                            <td class="px-table-cell-x py-table-cell-y align-top">
                                <x-ui.badge :variant="($table['mirror_exists'] ?? false) ? 'success' : 'default'">
                                    {{ ($table['mirror_exists'] ?? false) ? __('Present') : __('Missing') }}
                                </x-ui.badge>
                            </td>
                        </tr>
                    @endforeach
                </x-slot>
            </x-ui.table>

            @error('mirrorSelectedTables')
                <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
            @enderror

            <div class="flex flex-col gap-3 border-t border-border-default pt-4 lg:flex-row lg:items-center lg:justify-between">
                <p class="max-w-2xl text-xs leading-5 text-muted">
                    {{ $mirrorTransferMode === 'portable'
                        ? __('Both choices open a fresh inline review. Portable mode replaces rows only; both schemas must already match through migrations.')
                        : __('Both choices open a fresh inline review. Native PostgreSQL mode may replace, create, or delete selected complete tables.') }}
                </p>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <x-ui.button
                        variant="control"
                        wire:click="reviewMirror('push')"
                        :disabled="$mirrorSelectedCount === 0 || ! $canExecuteMirror"
                        wire:loading.attr="disabled"
                        wire:target="reviewMirror,executeMirror,forcePushMirror"
                    >
                        <x-icon name="heroicon-o-arrow-up-tray" class="h-4 w-4" />
                        <span wire:loading.remove wire:target="reviewMirror">
                            {{ $mirrorSelectedCount > 0
                                ? trans_choice('Push :count selected table to :provider|Push :count selected tables to :provider', $mirrorSelectedCount, ['count' => $mirrorSelectedCount, 'provider' => $mirrorProviderLabel])
                                : __('Push selected tables to :provider', ['provider' => $mirrorProviderLabel]) }}
                        </span>
                        <span wire:loading wire:target="reviewMirror">{{ __('Reviewing selected tables…') }}</span>
                    </x-ui.button>
                    <x-ui.button
                        variant="control"
                        wire:click="reviewMirror('pull')"
                        :disabled="$mirrorSelectedCount === 0 || ! $canExecuteMirror"
                        wire:loading.attr="disabled"
                        wire:target="reviewMirror,executeMirror,forcePushMirror"
                    >
                        <x-icon name="heroicon-o-arrow-down-tray" class="h-4 w-4" />
                        <span wire:loading.remove wire:target="reviewMirror">
                            {{ $mirrorSelectedCount > 0
                                ? trans_choice('Pull :count selected table from :provider|Pull :count selected tables from :provider', $mirrorSelectedCount, ['count' => $mirrorSelectedCount, 'provider' => $mirrorProviderLabel])
                                : __('Pull selected tables from :provider', ['provider' => $mirrorProviderLabel]) }}
                        </span>
                        <span wire:loading wire:target="reviewMirror">{{ __('Reviewing selected tables…') }}</span>
                    </x-ui.button>
                </div>
            </div>
        @endif
    @endif

    @if($mirrorReview)
        <div class="border-t border-border-default pt-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-base font-medium tracking-tight text-ink">
                        {{ $mirrorDirection === 'push' ? __('Review push to :provider', ['provider' => $mirrorProviderLabel]) : __('Review pull to Local') }}
                    </h2>
                    <p class="mt-1 text-sm text-muted">{{ __('This review contains only the explicit selection and will be recomputed before execution.') }}</p>
                </div>
                <x-ui.badge :variant="($mirrorReview['has_blockers'] ?? false) ? 'danger' : 'info'">
                    {{ ($mirrorReview['has_blockers'] ?? false) ? __('Blocked') : __('No changes yet') }}
                </x-ui.badge>
            </div>

            <div class="mt-4">
                <x-ui.table :caption="__('Selected table mirror actions')" size="xs">
                    <x-slot name="head">
                        <tr>
                            <x-ui.th>{{ __('Selected table') }}</x-ui.th>
                            <x-ui.th>{{ __('Action') }}</x-ui.th>
                            <x-ui.th>{{ __('Reason') }}</x-ui.th>
                        </tr>
                    </x-slot>
                    <x-slot name="body">
                        @foreach(($mirrorReview['items'] ?? []) as $item)
                            <tr wire:key="mirror-review-{{ $item['table'] }}">
                                <td class="px-table-cell-x py-table-cell-y align-top font-mono text-xs text-ink">{{ $item['table'] }}</td>
                                <td class="px-table-cell-x py-table-cell-y align-top">
                                    <x-ui.badge :variant="$mirrorActionVariant($item['action'])">{{ __(ucfirst($item['action'])) }}</x-ui.badge>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y align-top text-xs leading-5 text-muted">
                                    @forelse((array) ($item['blockers'] ?? []) as $blocker)
                                        <p>{{ $mirrorBlockerMessage($blocker) }}</p>
                                    @empty
                                        {{ match ($item['action']) {
                                            'create' => __('Source table will be created on the destination with its complete definition and rows.'),
                                            'replace' => $mirrorTransferMode === 'portable'
                                                ? __('Destination rows will be replaced by the complete source-table data; schema is unchanged.')
                                                : __('Destination table will be replaced by the complete source table.'),
                                            'delete' => __('Destination-only table will be deleted.'),
                                            default => __('Review the blocker before continuing.'),
                                        } }}
                                    @endforelse
                                </td>
                            </tr>
                        @endforeach
                    </x-slot>
                </x-ui.table>
            </div>

            <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs leading-5 text-muted">
                    {{ __('Unselected tables are outside this operation. There is no row merge, automatic dependency expansion, or DROP CASCADE.') }}
                </p>
                <div class="flex items-center gap-2">
                    <x-ui.button variant="ghost" wire:click="cancelMirrorReview" wire:loading.attr="disabled" wire:target="executeMirror,forcePushMirror">
                        {{ __('Cancel review') }}
                    </x-ui.button>
                    @if($mirrorDirection === 'push' && ($mirrorReview['_can_force_push'] ?? false))
                        <x-ui.button
                            variant="danger"
                            wire:click="forcePushMirror"
                            wire:confirm="{{ __('Force push this exact selection? Missing or incompatible remote tables will be dropped and recreated, and their remote rows will be replaced by Local. Unselected remote tables are untouched. Local schema and data will not be changed.') }}"
                            :disabled="! $canExecuteMirror"
                            wire:loading.attr="disabled"
                            wire:target="forcePushMirror"
                        >
                            <x-icon name="heroicon-o-exclamation-triangle" class="h-4 w-4" />
                            <span wire:loading.remove wire:target="forcePushMirror">
                                {{ trans_choice('Force push :count selected table|Force push :count selected tables', $mirrorSelectedCount, ['count' => $mirrorSelectedCount]) }}
                            </span>
                            <span wire:loading wire:target="forcePushMirror">{{ __('Replacing selected remote tables…') }}</span>
                        </x-ui.button>
                    @endif
                    <x-ui.button
                        :variant="$mirrorDirection === 'push' ? 'primary' : 'secondary'"
                        wire:click="executeMirror"
                        :disabled="($mirrorReview['has_blockers'] ?? true) || ! $canExecuteMirror"
                        wire:loading.attr="disabled"
                        wire:target="executeMirror,forcePushMirror"
                    >
                        <x-icon name="heroicon-o-check" class="h-4 w-4" />
                        <span wire:loading.remove wire:target="executeMirror">
                            {{ $mirrorDirection === 'push' ? __('Confirm push to :provider', ['provider' => $mirrorProviderLabel]) : __('Confirm pull to Local') }}
                        </span>
                        <span wire:loading wire:target="executeMirror">{{ __('Mirroring selected tables…') }}</span>
                    </x-ui.button>
                </div>
            </div>
        </div>
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
        </x-ui.alert>
        <x-ui.table :caption="__('Mirror row counts after the completed operation')" size="xs" container="plain">
            <x-slot name="head">
                <tr>
                    <x-ui.th>{{ __('Table') }}</x-ui.th>
                    <x-ui.th>{{ __('Action') }}</x-ui.th>
                    <x-ui.th class="text-right">{{ __('Local rows') }}</x-ui.th>
                    <x-ui.th class="text-right">{{ __('Remote rows') }}</x-ui.th>
                </tr>
            </x-slot>
            <x-slot name="body">
                @foreach((array) ($mirrorResult['items'] ?? []) as $item)
                    <tr wire:key="mirror-result-{{ $item['table'] }}">
                        <td class="px-table-cell-x py-table-cell-y font-mono text-xs text-ink">{{ $item['table'] }}</td>
                        <td class="px-table-cell-x py-table-cell-y">
                            <x-ui.badge :variant="$mirrorActionVariant($item['action'])">{{ __(ucfirst($item['action'])) }}</x-ui.badge>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-ink">
                            {{ array_key_exists('local_rows', $item) ? number_format((int) $item['local_rows']) : '—' }}
                        </td>
                        <td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-ink">
                            {{ array_key_exists('remote_rows', $item) ? number_format((int) $item['remote_rows']) : '—' }}
                        </td>
                    </tr>
                @endforeach
            </x-slot>
        </x-ui.table>
    @endif
</div>
