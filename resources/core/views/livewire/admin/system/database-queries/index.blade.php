<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Base\Database\Livewire\Queries\Index $this */
?>

<div>
    <x-slot name="title">{{ __('Database Queries') }}</x-slot>

    <div class="space-y-section-gap" x-data="{ localTime: false }">
        <x-ui.page-header :title="__('Database Queries')" :subtitle="__('User-defined SQL queries rendered as browsable pages')">
            <x-slot name="actions">
                <x-ui.button variant="primary" wire:click="createView">
                    <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                    {{ __('Create Query') }}
                </x-ui.button>
            </x-slot>
            <x-slot name="help">
                <p>{{ __('Database queries are user-defined SQL queries saved as named, pinnable pages. Each query stores a SELECT statement and displays the results in a table you can browse, sort, and search. Use them for custom reports, filtered datasets, or quick-access joins that the standard table browser doesn\'t cover.') }}</p>
            </x-slot>
        </x-ui.page-header>

        <x-ui.card>
            <div class="mb-2 flex items-center gap-4 flex-wrap">
                <div class="flex-1 min-w-0">
                    <x-ui.search-input
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search by name or description...') }}"
                    />
                </div>
                <x-ui.button
                    variant="ghost"
                    size="sm"
                    @click="localTime = !localTime"
                    ::class="localTime ? 'ring-2 ring-accent' : ''"
                    x-bind:aria-pressed="localTime.toString()"
                    title="{{ __('Toggle timestamp display between UTC and Local Time.') }}"
                    aria-label="{{ __('Toggle timestamp display between UTC and Local Time.') }}"
                >
                    <x-icon name="heroicon-o-clock" class="w-4 h-4" />
                    <span x-text="localTime ? '{{ __('Local Time') }}' : '{{ __('UTC') }}'"></span>
                </x-ui.button>
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Name') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Description') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Created') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Updated') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($views as $view)
                            @php
                                $viewUrl = route('admin.system.database-queries.show', $view->slug);
                            @endphp
                            <tr wire:key="query-{{ $view->id }}" class="hover:bg-surface-subtle/50 transition-colors cursor-pointer" tabindex="0" onclick="window.location='{{ $viewUrl }}'" onkeydown="if(event.key==='Enter'||event.key===' '){window.location='{{ $viewUrl }}';event.preventDefault();}">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink font-medium">{{ $view->name }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-muted" title="{{ $view->description }}">{{ Str::limit($view->description, 60) ?? '—' }}</td>
                                <td
                                    class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums"
                                    data-utc-display="{{ $view->created_at->format('Y-m-d H:i') }}"
                                    data-raw="{{ $view->created_at->utc()->format('Y-m-d\TH:i:s\Z') }}"
                                    x-data
                                    x-effect="
                                        if (localTime) {
                                            const raw = $el.getAttribute('data-raw');
                                            try { $el.textContent = raw ? new Date(raw).toLocaleString() : '—'; } catch(e) {}
                                        } else {
                                            $el.textContent = $el.getAttribute('data-utc-display') || '—';
                                        }
                                    "
                                >{{ $view->created_at->format('Y-m-d H:i') }}</td>
                                <td
                                    class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums"
                                    data-utc-display="{{ $view->updated_at->format('Y-m-d H:i') }}"
                                    data-raw="{{ $view->updated_at->utc()->format('Y-m-d\TH:i:s\Z') }}"
                                    x-data
                                    x-effect="
                                        if (localTime) {
                                            const raw = $el.getAttribute('data-raw');
                                            try { $el.textContent = raw ? new Date(raw).toLocaleString() : '—'; } catch(e) {}
                                        } else {
                                            $el.textContent = $el.getAttribute('data-utc-display') || '—';
                                        }
                                    "
                                >{{ $view->updated_at->format('Y-m-d H:i') }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm" onclick="event.stopPropagation()" onkeydown="event.stopPropagation()">
                                    <div class="flex items-center gap-2">
                                        <button
                                            wire:click="duplicateView({{ $view->id }})"
                                            class="text-accent hover:bg-surface-subtle rounded p-1 transition-colors cursor-pointer"
                                            title="{{ __('Duplicate') }}"
                                        >
                                            <x-icon name="heroicon-o-document-duplicate" class="size-4" />
                                        </button>
                                        <button
                                            wire:click="deleteView({{ $view->id }})"
                                            wire:confirm="{{ __('Are you sure you want to delete this query?') }}"
                                            class="text-accent hover:bg-surface-subtle rounded p-1 transition-colors cursor-pointer"
                                            title="{{ __('Delete') }}"
                                        >
                                            <x-icon name="heroicon-o-trash" class="size-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No queries found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                {{ $views->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
