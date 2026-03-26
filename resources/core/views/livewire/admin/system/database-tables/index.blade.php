<?php

use App\Base\Database\Livewire\DatabaseTables\Index;

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var Index $this */
?>
<div>
    <x-slot name="title">{{ __('Database Tables') }}</x-slot>

    <div class="space-y-section-gap" x-data="{ localTime: false }">
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
            <div class="mb-2 flex items-center gap-4 flex-wrap">
                <div class="flex-1 min-w-0">
                    <x-ui.search-input
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search by table name or module...') }}"
                    />
                </div>
                @if(app()->environment('local'))
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
                @endif
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">
                                <button wire:click="sort('table_name')" class="flex items-center gap-1 hover:text-ink transition-colors group">
                                    <span>{{ __('Table') }}</span>
                                    @if($sortBy === 'table_name')
                                        <x-icon name="{{ $sortDir === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down' }}" class="w-3 h-3 shrink-0" />
                                    @else
                                        <x-icon name="heroicon-m-chevron-up-down" class="w-3 h-3 shrink-0 opacity-0 group-hover:opacity-60 transition-opacity" />
                                    @endif
                                </button>
                            </th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">
                                <button wire:click="sort('module_name')" class="flex items-center gap-1 hover:text-ink transition-colors group">
                                    <span>{{ __('Module') }}</span>
                                    @if($sortBy === 'module_name')
                                        <x-icon name="{{ $sortDir === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down' }}" class="w-3 h-3 shrink-0" />
                                    @else
                                        <x-icon name="heroicon-m-chevron-up-down" class="w-3 h-3 shrink-0 opacity-0 group-hover:opacity-60 transition-opacity" />
                                    @endif
                                </button>
                            </th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">
                                <button wire:click="sort('migration_file')" class="flex items-center gap-1 hover:text-ink transition-colors group">
                                    <span>{{ __('Migration') }}</span>
                                    @if($sortBy === 'migration_file')
                                        <x-icon name="{{ $sortDir === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down' }}" class="w-3 h-3 shrink-0" />
                                    @else
                                        <x-icon name="heroicon-m-chevron-up-down" class="w-3 h-3 shrink-0 opacity-0 group-hover:opacity-60 transition-opacity" />
                                    @endif
                                </button>
                            </th>
                            @if(app()->environment('local'))
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">
                                    <button wire:click="sort('is_stable')" class="flex items-center gap-1 hover:text-ink transition-colors group">
                                        <span>{{ __('Stable') }}</span>
                                        @if($sortBy === 'is_stable')
                                            <x-icon name="{{ $sortDir === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down' }}" class="w-3 h-3 shrink-0" />
                                        @else
                                            <x-icon name="heroicon-m-chevron-up-down" class="w-3 h-3 shrink-0 opacity-0 group-hover:opacity-60 transition-opacity" />
                                        @endif
                                    </button>
                                </th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">
                                    <button wire:click="sort('stabilized_at')" class="flex items-center gap-1 hover:text-ink transition-colors group">
                                        <span>{{ __('Stabilized At') }}</span>
                                        @if($sortBy === 'stabilized_at')
                                            <x-icon name="{{ $sortDir === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down' }}" class="w-3 h-3 shrink-0" />
                                        @else
                                            <x-icon name="heroicon-m-chevron-up-down" class="w-3 h-3 shrink-0 opacity-0 group-hover:opacity-60 transition-opacity" />
                                        @endif
                                    </button>
                                </th>
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
                                    <td
                                        class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums"
                                        @if($table->stabilized_at)
                                            data-utc-display="{{ $table->stabilized_at->format('Y-m-d H:i:s') }}"
                                            data-raw="{{ $table->stabilized_at->utc()->format('Y-m-d\TH:i:s\Z') }}"
                                            x-data
                                            x-effect="
                                                if (localTime) {
                                                    const raw = $el.getAttribute('data-raw');
                                                    try { $el.textContent = raw ? new Date(raw).toLocaleString() : '—'; } catch(e) {}
                                                } else {
                                                    $el.textContent = $el.getAttribute('data-utc-display') || '—';
                                                }
                                            "
                                        @endif
                                    >{{ $table->stabilized_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
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
