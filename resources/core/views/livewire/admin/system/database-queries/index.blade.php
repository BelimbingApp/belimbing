<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Base\Database\Livewire\Queries\Index $this */
?>

<div>
    <x-slot name="title">{{ __('Database Queries') }}</x-slot>

    <div class="space-y-section-gap">
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
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <x-ui.sortable-th
                                column="name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('name')"
                                :label="__('Name')"
                            />
                            <x-ui.sortable-th
                                column="description"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('description')"
                                :label="__('Description')"
                            />
                            <x-ui.sortable-th
                                column="created_at"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('created_at')"
                                :label="__('Created')"
                            />
                            <x-ui.sortable-th
                                column="updated_at"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('updated_at')"
                                :label="__('Updated')"
                            />
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
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">
                                    <x-ui.datetime :value="$view->created_at" />
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">
                                    <x-ui.datetime :value="$view->updated_at" />
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right text-sm" onclick="event.stopPropagation()" onkeydown="event.stopPropagation()">
                                    <x-ui.icon-action-group>
                                        <x-ui.icon-action
                                            icon="heroicon-o-document-duplicate"
                                            :label="__('Duplicate query')"
                                            :title="__('Duplicate')"
                                            wire:click="duplicateView({{ $view->id }})"
                                        />
                                        <x-ui.icon-action
                                            icon="heroicon-o-trash"
                                            :label="__('Delete query')"
                                            :title="__('Delete')"
                                            wire:click="deleteView({{ $view->id }})"
                                            wire:confirm="{{ __('Are you sure you want to delete this query?') }}"
                                        />
                                    </x-ui.icon-action-group>
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
