<?php

use App\Base\Database\Livewire\SchemaIncubation\Index;

/** @var Index $this */
?>
<div>
    <x-slot name="title">{{ __('Schema Incubation') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Schema Incubation')" :subtitle="__('Manage source-local IncubatingSchema markers on owning migration files')">
            <x-slot name="help">
                <p>{{ __('This screen edits migration files by adding or removing `use IncubatingSchema;`. Actions operate at migration scope, so selecting one table can move sibling tables from the same migration too.') }}</p>
                <p class="mt-2">{{ __('Wildcard search is supported for discovery, but only source-local incubation is editable here. Tables still covered only by the deprecated compatibility script are shown as read-only so you can migrate them deliberately.') }}</p>
            </x-slot>
        </x-ui.page-header>

        @if (session('warning'))
            <x-ui.alert variant="warning">{{ session('warning') }}</x-ui.alert>
        @endif

        @if (session('status'))
            <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
        @endif

        @foreach($this->orphanedRegistryNotices as $index => $notice)
            <x-ui.alert variant="warning" class="flex items-start justify-between gap-3">
                <span>{{ $notice }}</span>
                <button
                    type="button"
                    wire:click="dismissNotice({{ $index }})"
                    class="shrink-0 text-muted hover:text-ink transition-colors"
                    aria-label="{{ __('Dismiss notice') }}"
                >
                    <x-icon name="heroicon-o-x-mark" class="w-4 h-4" />
                </button>
            </x-ui.alert>
        @endforeach

        <x-ui.card>
            <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div class="space-y-1">
                    <h2 class="text-sm font-semibold text-ink">{{ __('Currently Incubating') }}</h2>
                    <p class="text-sm text-muted">{{ __('Only tables already under incubation are listed here. Removing a selected table removes `use IncubatingSchema;` from its owning migration, which may also move sibling tables from that migration out of incubation.') }}</p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <x-ui.button wire:click="removeSelectedFromIncubation" variant="ghost" size="sm">
                        {{ __('Remove Selected From Incubation') }}
                    </x-ui.button>
                </div>
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">
                                <x-ui.checkbox wire:model.live="selectIncubatingPage" id="select-visible-incubation-tables" />
                            </th>
                            <x-ui.sortable-th
                                column="table_name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                :label="__('Table')"
                            />
                            <x-ui.sortable-th
                                column="module_name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                :label="__('Module')"
                            />
                            <x-ui.sortable-th
                                column="migration_file"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                :label="__('Migration')"
                            />
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Schema') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Incubation Source') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($incubatingTables as $table)
                            @php
                                $incubationSource = $table->source_declared
                                    ? __('Source migration')
                                    : ($table->deprecated_pattern ? __('Deprecated compatibility list: :pattern', ['pattern' => $table->deprecated_pattern]) : __('Not incubating'));
                            @endphp
                            <tr wire:key="incubation-table-{{ $table->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <x-ui.checkbox
                                        wire:model.live="selectedIncubatingTables"
                                        value="{{ $table->table_name }}"
                                        id="incubation-table-select-{{ $table->id }}"
                                    />
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink font-mono">{{ $table->table_name }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $table->module_name ?? '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted font-mono text-xs" title="{{ $table->migration_file }}">{{ $table->migration_file ? Str::limit($table->migration_file, 50) : '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <x-ui.badge :variant="$this->schemaStateVariant($table->schema_state)">
                                        {{ Str::headline($table->schema_state) }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ $incubationSource }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No tables are currently under incubation.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                {{ $incubatingTables->links() }}
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div class="flex-1">
                    <x-ui.search-input
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search stable tables by table name, module, or migration… Wildcards like people_* and ai_?rowser_* work here.') }}"
                    />
                </div>

                <div class="flex flex-wrap gap-2">
                    <x-ui.button wire:click="moveSelectedToIncubation" variant="secondary" size="sm">
                        {{ __('Move Selected To Incubation') }}
                    </x-ui.button>
                </div>
            </div>

            <div class="mb-4 rounded-lg border border-border-default bg-surface-subtle/60 px-4 py-3 text-sm text-muted">
                {{ __('Search results only show tables that are not currently under incubation. Moving a selected table edits its owning migration file to add `use IncubatingSchema;`. Tables still covered only by the deprecated compatibility script are intentionally excluded here.') }}
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">
                                <x-ui.checkbox wire:model.live="selectSearchPage" id="select-visible-search-tables" />
                            </th>
                            <x-ui.sortable-th
                                column="table_name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                :label="__('Table')"
                            />
                            <x-ui.sortable-th
                                column="module_name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                :label="__('Module')"
                            />
                            <x-ui.sortable-th
                                column="migration_file"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                :label="__('Migration')"
                            />
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @if(trim($search) === '')
                            <tr>
                                <td colspan="4" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('Search for tables to move into incubation.') }}</td>
                            </tr>
                        @else
                            @forelse($searchTables as $table)
                                <tr wire:key="search-table-{{ $table->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                        <x-ui.checkbox
                                            wire:model.live="selectedSearchTables"
                                            value="{{ $table->table_name }}"
                                            id="search-table-select-{{ $table->id }}"
                                        />
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink font-mono">{{ $table->table_name }}</td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $table->module_name ?? '—' }}</td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted font-mono text-xs" title="{{ $table->migration_file }}">{{ $table->migration_file ? Str::limit($table->migration_file, 50) : '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No stable tables match this search.') }}</td>
                                </tr>
                            @endforelse
                        @endif
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                {{ $searchTables->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
