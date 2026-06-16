<?php

use App\Base\Database\Livewire\SchemaIncubation\Index;

/** @var Index $this */

$migrationScopeHelp = __('This screen edits migration files by adding or removing `use IncubatingSchema;`. Actions operate at migration scope, so selecting one table can move sibling tables from the same migration too.');
$sourceOnlyHelp = __('Only source-local incubation is editable here.');
$addHelp = __('Moving a selected table edits its owning migration file to add `use IncubatingSchema;`.');
?>
<div>
    <x-slot name="title">{{ __('Schema Incubation') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Schema Incubation')" :subtitle="__('Keep still-changing tables editable in their original migration files during development, instead of adding new migrations for schema changes')">
            <x-slot name="help">
                <p>{{ $migrationScopeHelp }}</p>
                <p class="mt-2">{{ __('Wildcard search is supported for discovery. :sourceOnly', ['sourceOnly' => $sourceOnlyHelp]) }}</p>
            </x-slot>
        </x-ui.page-header>

        @unless (app()->environment('local'))
            <x-ui.alert variant="warning">
                <p class="font-semibold">{{ __('Schema incubation is a local development workflow.') }}</p>
                <p class="mt-1">{{ __('This environment is :env, not local. Incubating schema is blocked from migrating outside local/testing, so changes made here will not apply on deploy and migration files should not be edited in this environment. Graduate a migration (remove its incubating marker) before it ships.', ['env' => app()->environment()]) }}</p>
            </x-ui.alert>
        @endunless

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
            <div class="mb-4 space-y-3">
                <h2 class="text-sm font-semibold text-ink">{{ __('Currently Incubating') }}</h2>

                <div class="flex flex-col gap-3 xl:flex-row xl:items-end">
                    <div class="grid flex-1 gap-3 lg:grid-cols-[minmax(0,1fr)_16rem]">
                        <x-ui.search-input
                            wire:model.live.debounce.300ms="incubatingSearch"
                            placeholder="{{ __('Filter incubating tables by table name…') }}"
                        />

                        <x-ui.select id="incubating-module-filter" wire:model.live="incubatingModule">
                            <option value="">{{ __('All modules') }}</option>
                            @foreach($incubatingModules as $module)
                                <option value="{{ $module }}">{{ $module }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <div class="flex flex-wrap gap-2">
                    <x-ui.button wire:click="removeSelectedFromIncubation" variant="primary" size="md" class="whitespace-nowrap">
                        {{ __('Un-incubate') }}
                    </x-ui.button>
                    </div>
                </div>
            </div>

            <x-ui.table container="flush" :caption="__('Incubating tables')">

                    <x-slot name="head">
                        <tr>
                            <x-ui.th><x-ui.checkbox wire:model.live="selectIncubatingPage" id="select-visible-incubation-tables" /></x-ui.th>
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
                            <x-ui.th>{{ __('Schema') }}</x-ui.th>
                        </tr>
                    </x-slot>

                        @forelse($incubatingTables as $table)
                            <tr wire:key="incubation-table-{{ $table->id }}">
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
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No tables are currently under incubation.') }}</td>
                            </tr>
                        @endforelse


            </x-ui.table>

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
                {{ __('Search results only show tables that are not currently under source-local incubation. :addHelp', ['addHelp' => $addHelp]) }}
            </div>

            <x-ui.table container="flush" :caption="__('Incubating migrations')">

                    <x-slot name="head">
                        <tr>
                            <x-ui.th><x-ui.checkbox wire:model.live="selectSearchPage" id="select-visible-search-tables" /></x-ui.th>
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
                    </x-slot>

                        @if(trim($search) === '')
                            <tr>
                                <td colspan="4" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('Search for tables to move into incubation.') }}</td>
                            </tr>
                        @else
                            @forelse($searchTables as $table)
                                <tr wire:key="search-table-{{ $table->id }}">
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
                                </x-ui.table>

            <div class="mt-2">
                {{ $searchTables->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
