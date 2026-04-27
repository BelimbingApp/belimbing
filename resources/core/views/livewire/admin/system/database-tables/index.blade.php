<?php

use App\Base\Database\Livewire\DatabaseTables\Index;

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var Index $this */
?>
<div>
    <x-slot name="title">{{ __('Database Tables') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Database Tables')" :subtitle="__('Browse and inspect all registered database tables')">
            <x-slot name="help">
                @if(app()->environment('local'))
                    <p>{{ __('During development, migrate:fresh wipes all tables and rebuilds from scratch. The Stable flag prevents this for tables whose data is already correct — avoiding unnecessary rebuilds and re-seeding of stable features. Click the badge to toggle. Infrastructure tables (migrations, base_database_tables, base_database_seeders) are always preserved regardless of this flag.') }}</p>
                @endif
                <p class="{{ app()->environment('local') ? 'mt-2' : '' }}">{{ __('Click any row to browse its contents. For advanced queries — filtering, joins, aggregations, or data edits — ask Lara via the status bar.') }}</p>
            </x-slot>
        </x-ui.page-header>

        @if (session('warning'))
            <x-ui.alert variant="warning">{{ session('warning') }}</x-ui.alert>
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
            <div class="mb-2">
                <x-ui.search-input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by table name, module, or migration...') }}"
                />
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
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
                            @if(app()->environment('local'))
                                <x-ui.sortable-th
                                    column="is_stable"
                                    :sort-by="$sortBy"
                                    :sort-dir="$sortDir"
                                    :label="__('Stable')"
                                />
                                <x-ui.sortable-th
                                    column="stabilized_at"
                                    :sort-by="$sortBy"
                                    :sort-dir="$sortDir"
                                    :label="__('Stabilized At')"
                                />
                            @endif
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($tables as $table)
                            @php
                                $tableUrl = route('admin.system.database-tables.show', $table->table_name);
                            @endphp
                            <tr wire:key="table-{{ $table->id }}" class="hover:bg-surface-subtle/50 transition-colors cursor-pointer" tabindex="0" onclick="window.location='{{ $tableUrl }}'" onkeydown="if(event.key==='Enter'||event.key===' '){window.location='{{ $tableUrl }}';event.preventDefault();}">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink font-mono">{{ $table->table_name }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $table->module_name ?? '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted font-mono text-xs" title="{{ $table->migration_file }}">{{ $table->migration_file ? Str::limit($table->migration_file, 50) : '—' }}</td>
                                @if(app()->environment('local'))
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                        <button
                                            wire:click="toggleStability({{ $table->id }})"
                                            onclick="event.stopPropagation()"
                                            class="cursor-pointer"
                                            title="{{ $table->is_stable ? __('Click to mark unstable') : __('Click to mark stable') }}"
                                        >
                                            <x-ui.badge :variant="$this->stabilityVariant($table->is_stable)">
                                                {{ $table->is_stable ? __('Stable') : __('Unstable') }}
                                            </x-ui.badge>
                                        </button>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">
                                        <x-ui.datetime :value="$table->stabilized_at" />
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ app()->environment('local') ? 5 : 3 }}" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No tables registered.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                {{ $tables->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
