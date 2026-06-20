@php
    $entries = $sourceHistory['entries'] ?? [];
    $shownCount = count($entries);
    $totalCount = (int) ($sourceHistory['total'] ?? $shownCount);
    $searchActive = trim($sourceHistorySearch ?? '') !== '';
    $hasMore = (bool) ($sourceHistory['has_more'] ?? false);
    $subjectLabel = trim($sourceHistorySubjectLabel ?? '');
@endphp

<x-ui.inspector-drawer
    wire:model="sourceHistoryDrawerOpen"
    close-action="closeSourceHistory"
    labelledby="audit-source-history-title"
>
            <header class="border-b border-border-default p-card-inner">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h2 id="audit-source-history-title" class="text-[11px] font-semibold uppercase tracking-wider text-muted">
                            {{ __('Record history') }}
                            @if ($subjectLabel !== '')
                                <span class="font-mono normal-case tracking-normal text-ink">· {{ $subjectLabel }}</span>
                            @endif
                        </h2>
                        <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted">
                            @if ($shownCount < $totalCount)
                                <span>{{ __('Showing :shown of :total mutations', ['shown' => $shownCount, 'total' => $totalCount]) }}</span>
                            @else
                                <span>{{ trans_choice(':count mutation|:count mutations', $totalCount, ['count' => $totalCount]) }}</span>
                            @endif

                            @if ($sourceHistoryAllUrl !== '')
                                <a href="{{ $sourceHistoryAllUrl }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline">
                                    {{ __('Data Mutations') }}
                                    <x-icon name="heroicon-o-arrow-top-right-on-square" class="size-3.5" />
                                </a>
                            @endif
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-1">
                        <x-ui.inspector-default-width-button />

                        <button
                            type="button"
                            wire:click="closeSourceHistory"
                            class="rounded p-1 text-muted transition-colors hover:bg-surface-subtle hover:text-ink focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2"
                            aria-label="{{ __('Close record history') }}"
                        >
                            <x-icon name="heroicon-o-x-mark" class="size-5" />
                        </button>
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <x-ui.search-input
                        id="audit-source-history-search"
                        wire:model.live.debounce.300ms="sourceHistorySearch"
                        placeholder="{{ __('Search actor, field, value, event, trace...') }}"
                        class="w-full sm:w-96"
                    />
                    @if ($searchActive)
                        <button
                            type="button"
                            wire:click="clearSourceHistorySearch"
                            class="rounded p-2 text-muted transition-colors hover:bg-surface-subtle hover:text-ink focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2"
                            aria-label="{{ __('Clear history search') }}"
                        >
                            <x-icon name="heroicon-o-x-mark" class="size-4" />
                        </button>
                    @endif
                </div>
            </header>

            <div class="min-h-0 flex-1 overflow-y-auto p-card-inner">
                @if ($entries === [])
                    <div wire:key="source-history-empty" class="rounded-lg border border-border-default bg-surface-subtle p-4 text-sm text-muted">
                        {{ $searchActive ? __('No matching mutations found.') : __('No mutations have been recorded for this record yet.') }}
                    </div>
                @else
                    <x-ui.table
                        wire:key="source-history-table"
                        x-show="! isMobile && panelWidth >= 640"
                        x-cloak
                        container="plain"
                        size="xs"
                        :caption="__('Record history mutations')"
                        :sticky-header="true"
                        :row-hover="true"
                        class="rounded-lg border border-border-default"
                    >
                            <x-slot name="head">
                                <tr>
                                    <x-ui.sortable-th
                                        column="occurred_at"
                                        :sort-by="$sourceHistorySortBy"
                                        :sort-dir="$sourceHistorySortDir"
                                        action="sortSourceHistory('occurred_at')"
                                        :label="__('Time')"
                                    />
                                    <x-ui.sortable-th
                                        column="actor"
                                        :sort-by="$sourceHistorySortBy"
                                        :sort-dir="$sourceHistorySortDir"
                                        action="sortSourceHistory('actor')"
                                        :label="__('Actor')"
                                    />
                                    <x-ui.sortable-th
                                        column="event"
                                        :sort-by="$sourceHistorySortBy"
                                        :sort-dir="$sourceHistorySortDir"
                                        action="sortSourceHistory('event')"
                                        :label="__('Event')"
                                    />
                                    <x-ui.sortable-th
                                        column="trace_id"
                                        :sort-by="$sourceHistorySortBy"
                                        :sort-dir="$sourceHistorySortDir"
                                        action="sortSourceHistory('trace_id')"
                                        :label="__('Trace')"
                                    />
                                    <x-ui.th>{{ __('Changes') }}</x-ui.th>
                                </tr>
                            </x-slot>

                            @foreach ($entries as $entry)
                                <tr wire:key="source-history-table-{{ $entry['id'] }}" class="align-top">
                                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-xs tabular-nums text-muted">
                                        <x-ui.datetime :value="$entry['occurred_at']" />
                                    </td>
                                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-xs">
                                        <div class="text-ink">{{ $entry['actor'] }}</div>
                                        @if (! empty($entry['actor_role']))
                                            <div class="mt-0.5 font-mono text-[11px] text-muted">{{ $entry['actor_role'] }}</div>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y">
                                        <x-ui.badge :variant="$entry['event_variant']">{{ $entry['event_label'] }}</x-ui.badge>
                                    </td>
                                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y font-mono text-xs">
                                        @if (! empty($entry['trace_id']))
                                            <button
                                                type="button"
                                                wire:click="openTrace('{{ $entry['trace_id'] }}')"
                                                class="text-accent hover:underline focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2"
                                            >
                                                {{ $entry['formatted_trace_id'] }}
                                            </button>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="min-w-[260px] px-table-cell-x py-table-cell-y">
                                        @if (($entry['target'] ?? '') !== '')
                                            <div class="mb-1 font-mono text-[11px] text-muted">{{ $entry['target'] }}</div>
                                        @endif
                                        <div class="flex flex-wrap gap-1.5">
                                            @forelse ($entry['diffs'] ?? [] as $diff)
                                                <div class="inline-flex max-w-full items-center gap-1.5 rounded bg-surface-subtle px-2 py-1 font-mono text-xs">
                                                    <span class="font-semibold text-muted">{{ $diff['field'] }}</span>
                                                    @if($diff['sensitive'])
                                                        <code class="max-w-64 truncate text-muted italic">{{ $diff['old'] }} → {{ $diff['new'] }}</code>
                                                    @else
                                                        <code class="max-w-48 truncate text-status-danger">{{ $diff['old'] }}</code>
                                                        <span class="text-muted">→</span>
                                                        <code class="max-w-48 truncate text-status-success">{{ $diff['new'] }}</code>
                                                    @endif
                                                </div>
                                            @empty
                                                <span class="text-xs italic text-muted">{{ __('No field changes recorded.') }}</span>
                                            @endforelse
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                    </x-ui.table>

                    <ol x-show="isMobile || panelWidth < 640" x-cloak class="overflow-hidden rounded-lg border border-border-default bg-surface-card">
                        @foreach ($entries as $entry)
                            <li wire:key="source-history-list-{{ $entry['id'] }}" class="border-b border-border-default px-3 py-2 first:pt-3 last:border-b-0 last:pb-3">
                                <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start">
                                    <div class="min-w-0 space-y-1">
                                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                            <x-ui.badge :variant="$entry['event_variant']">{{ $entry['event_label'] }}</x-ui.badge>
                                            @if (($entry['target'] ?? '') !== '')
                                                <span class="truncate font-mono text-xs text-muted">{{ $entry['target'] }}</span>
                                            @endif
                                        </div>
                                        <p class="text-xs text-muted">
                                            {{ $entry['actor'] }}
                                            @if (! empty($entry['actor_role']))
                                                <span aria-hidden="true">·</span>
                                                <span class="font-mono">{{ $entry['actor_role'] }}</span>
                                            @endif
                                        </p>
                                    </div>
                                    <div class="text-left text-xs text-muted sm:text-right">
                                        <div class="tabular-nums"><x-ui.datetime :value="$entry['occurred_at']" /></div>
                                        @if (! empty($entry['trace_id']))
                                            <button
                                                type="button"
                                                wire:click="openTrace('{{ $entry['trace_id'] }}')"
                                                class="mt-1 font-mono text-accent hover:underline focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2"
                                            >
                                                {{ $entry['formatted_trace_id'] }}
                                            </button>
                                        @endif
                                    </div>
                                </div>

                                <div class="mt-2 flex flex-wrap gap-1.5 border-t border-border-default pt-2">
                                    @forelse ($entry['diffs'] ?? [] as $diff)
                                        <div class="inline-flex max-w-full items-center gap-1.5 rounded bg-surface-subtle px-2 py-1 font-mono text-xs">
                                            <span class="font-semibold text-muted">{{ $diff['field'] }}</span>
                                            @if($diff['sensitive'])
                                                <code class="max-w-56 truncate text-muted italic">{{ $diff['old'] }} → {{ $diff['new'] }}</code>
                                            @else
                                                <code class="max-w-36 truncate text-status-danger">{{ $diff['old'] }}</code>
                                                <span class="text-muted">→</span>
                                                <code class="max-w-36 truncate text-status-success">{{ $diff['new'] }}</code>
                                            @endif
                                        </div>
                                    @empty
                                        <p class="text-xs italic text-muted">{{ __('No field changes recorded.') }}</p>
                                    @endforelse
                                </div>
                            </li>
                        @endforeach
                    </ol>

                    @if ($hasMore)
                        <div class="mt-3 flex items-center justify-between gap-3 text-xs text-muted">
                            <span>{{ __('Showing :shown of :total mutations', ['shown' => $shownCount, 'total' => $totalCount]) }}</span>
                            <button
                                type="button"
                                wire:click="loadMoreSourceHistory"
                                wire:loading.attr="disabled"
                                class="inline-flex items-center gap-1 rounded px-2 py-1 font-medium text-accent transition-colors hover:bg-surface-subtle hover:text-accent-hover focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2 disabled:opacity-50"
                            >
                                <x-icon name="heroicon-o-arrow-down-circle" class="size-4" />
                                {{ __('Load more') }}
                            </button>
                        </div>
                    @endif
                @endif
            </div>
</x-ui.inspector-drawer>
