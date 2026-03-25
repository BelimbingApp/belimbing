<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Base\Database\Livewire\DatabaseTables\Show $this */
?>

<div
    x-data="{
        localTime: false,
        navFilter: '',
        navOpen: @js($this->navigatorOpen),
        navWidth: parseInt(localStorage.getItem('tableNavWidth')) || 208,
        _navDragging: false,
        NAV_MIN: 160,
        NAV_MAX: 320,
        toggleNav() {
            this.navOpen = !this.navOpen;
            $wire.toggleNavigator();
        },
        startNavDrag(e) {
            this._navDragging = true;
            const startX = e.clientX;
            const startWidth = this.navWidth;
            document.documentElement.style.cursor = 'col-resize';
            document.documentElement.style.userSelect = 'none';

            const onMove = (e) => {
                this.navWidth = Math.max(this.NAV_MIN, Math.min(this.NAV_MAX, startWidth + (e.clientX - startX)));
            };
            const onUp = () => {
                this._navDragging = false;
                document.documentElement.style.cursor = '';
                document.documentElement.style.userSelect = '';
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                localStorage.setItem('tableNavWidth', this.navWidth);
            };
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        },
    }"
    class="flex gap-0 -mx-1 -my-2 sm:-mx-4 sm:-my-1 h-[calc(100vh-(--spacing(11))-(--spacing(6)))]"
>
    {{-- Table Navigator Panel --}}
    <div
        x-show="navOpen"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="-translate-x-full opacity-0"
        x-transition:enter-end="translate-x-0 opacity-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="translate-x-0 opacity-100"
        x-transition:leave-end="-translate-x-full opacity-0"
        x-cloak
        class="hidden lg:flex shrink-0 relative"
        :style="'width: ' + navWidth + 'px'"
    >
    <aside class="flex flex-col w-full border-r border-border-default bg-surface-sidebar overflow-hidden">
        {{-- Navigator Header --}}
        <div class="flex items-center justify-between px-2 py-1.5 border-b border-border-default">
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted select-none">{{ __('Tables') }}</span>
            <button
                @click="toggleNav()"
                class="text-muted hover:text-ink transition-colors p-0.5"
                title="{{ __('Close navigator') }}"
            >
                <x-icon name="heroicon-o-x-mark" class="w-3.5 h-3.5" />
            </button>
        </div>

        {{-- Recently Viewed --}}
        @if(count($recentTables) > 1)
            <div class="px-0.5 py-0.5 bg-surface-pinned rounded-sm" x-data="{ recentOpen: true }">
                <button
                    @click="recentOpen = !recentOpen"
                    class="flex items-center w-full px-1 py-0.5 text-[10px] uppercase tracking-wider font-semibold text-muted hover:text-ink transition-colors select-none"
                >
                    <x-icon
                        name="heroicon-m-chevron-right"
                        class="w-3 h-3 mr-0.5 transition-transform duration-150 shrink-0"
                        x-bind:class="recentOpen ? 'rotate-90' : ''"
                    />
                    {{ __('Recent') }}
                </button>
                <div x-show="recentOpen">
                    @foreach($recentTables as $recent)
                        @if($recent !== $this->tableName)
                            <a
                                href="{{ route('admin.system.database-tables.show', $recent) }}"
                                wire:navigate
                                class="flex items-center px-1.5 py-0.5 text-xs font-mono rounded-sm transition text-link hover:bg-surface-subtle truncate"
                                x-show="!navFilter || '{{ $recent }}'.toLowerCase().includes(navFilter.toLowerCase())"
                            >
                                <x-icon name="heroicon-o-clock" class="w-3 h-3 mr-1.5 text-muted shrink-0" />
                                <span class="truncate">{{ $recent }}</span>
                            </a>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Navigator Search --}}
        <div class="px-2 py-1.5">
            <div class="relative">
                <x-icon
                    name="heroicon-o-magnifying-glass"
                    class="absolute left-2 top-1/2 -translate-y-1/2 h-3 w-3 text-muted pointer-events-none"
                />
                <input
                    type="search"
                    x-model.debounce.200ms="navFilter"
                    placeholder="{{ __('Filter tables...') }}"
                    class="w-full pl-7 pr-2 py-1 text-xs border border-border-input rounded-lg bg-surface-card text-ink placeholder:text-muted focus:outline-none focus:ring-1 focus:ring-accent focus:border-transparent [&::-webkit-search-cancel-button]:appearance-none"
                />
            </div>
        </div>

        {{-- Table List Grouped by Module --}}
        <nav
            x-ref="navList"
            x-init="$nextTick(() => { const el = $refs.navList?.querySelector('[data-active]'); if (el) el.scrollIntoView({ block: 'center' }); })"
            class="flex-1 overflow-y-auto px-2 pb-2"
            aria-label="{{ __('Table navigator') }}"
        >
            @foreach($tablesGrouped as $module => $tables)
                <div
                    x-data="{ expanded: true }"
                    x-show="!navFilter || {{ json_encode(collect($tables)->pluck('table_name')->all()) }}.some(t => t.toLowerCase().includes(navFilter.toLowerCase()) || '{{ strtolower($module) }}'.includes(navFilter.toLowerCase()))"
                >
                    <button
                        @click="expanded = !expanded"
                        class="flex items-center w-full px-1 py-0.5 text-[10px] uppercase tracking-wider font-semibold text-muted hover:text-ink transition-colors select-none"
                    >
                        <x-icon
                            name="heroicon-m-chevron-right"
                            class="w-3 h-3 mr-0.5 transition-transform duration-150 shrink-0"
                            x-bind:class="expanded ? 'rotate-90' : ''"
                        />
                        {{ $module }}
                        <span class="ml-auto text-[9px] font-normal tabular-nums opacity-60">{{ count($tables) }}</span>
                    </button>
                    <div x-show="expanded">
                        @foreach($tables as $tableEntry)
                            @php $isCurrentTable = $tableEntry['table_name'] === $this->tableName; @endphp
                            <a
                                href="{{ route('admin.system.database-tables.show', $tableEntry['table_name']) }}"
                                wire:navigate
                                @class([
                                    'flex items-center px-1.5 py-0.5 text-xs font-mono rounded-sm transition truncate',
                                    'bg-accent/10 text-accent font-medium' => $isCurrentTable,
                                    'text-link hover:bg-surface-subtle' => ! $isCurrentTable,
                                ])
                                x-show="!navFilter || '{{ $tableEntry['table_name'] }}'.toLowerCase().includes(navFilter.toLowerCase())"
                                @if($isCurrentTable) aria-current="page" data-active @endif
                            >
                                <span class="truncate">{{ $tableEntry['table_name'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </nav>
    </aside>

        {{-- Drag handle --}}
        <div
            @mousedown.prevent="startNavDrag($event)"
            class="absolute top-0 bottom-0 right-0 w-1 cursor-col-resize z-20 group"
        >
            <div
                class="w-full h-full transition-colors"
                :class="_navDragging ? 'bg-accent' : 'group-hover:bg-border-default'"
            ></div>
        </div>
    </div>

    {{-- Main Content --}}
    <div class="flex-1 min-w-0 overflow-y-auto px-1 py-2 sm:px-4 sm:py-1">
        <x-slot name="title">{{ $this->tableName }}</x-slot>

        <div class="space-y-section-gap">
            <x-ui.page-header
                :title="$this->tableName"
                :subtitle="trans_choice(':count row|:count rows', $rowCount, ['count' => number_format($rowCount)])"
                :pinnable="[
                    'label' => $this->tableName,
                    'url' => request()->url(),
                    'icon' => 'heroicon-o-table-cells',
                ]"
            >
                <x-slot name="actions">
                    <x-ui.button
                        x-show="!navOpen"
                        x-cloak
                        variant="ghost"
                        size="sm"
                        x-on:click="toggleNav()"
                        title="{{ __('Open table navigator') }}"
                    >
                        <x-icon name="heroicon-o-bars-3-bottom-left" class="w-4 h-4" />
                        {{ __('Tables') }}
                    </x-ui.button>
                    <x-ui.button variant="ghost" size="sm" href="{{ route('admin.system.database-tables.index') }}">
                        <x-icon name="heroicon-o-arrow-left" class="w-4 h-4" />
                        {{ __('Back to Registry') }}
                    </x-ui.button>
                </x-slot>
            </x-ui.page-header>

            {{-- Related Tables (Foreign Keys) --}}
            @if(count($foreignKeys['outgoing']) > 0 || count($foreignKeys['incoming']) > 0)
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    @if(count($foreignKeys['outgoing']) > 0)
                        <span class="text-muted font-medium">{{ __('References:') }}</span>
                        @foreach($foreignKeys['outgoing'] as $fk)
                            <a
                                href="{{ route('admin.system.database-tables.show', $fk['foreign_table']) }}"
                                wire:navigate
                                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full border border-border-default bg-surface-card text-link hover:bg-surface-subtle transition-colors font-mono"
                                title="{{ $fk['column'] }} → {{ $fk['foreign_table'] }}.{{ $fk['foreign_column'] }}"
                            >
                                <x-icon name="heroicon-o-arrow-top-right-on-square" class="w-3 h-3" />
                                {{ $fk['foreign_table'] }}
                            </a>
                        @endforeach
                    @endif
                    @if(count($foreignKeys['incoming']) > 0)
                        <span class="text-muted font-medium {{ count($foreignKeys['outgoing']) > 0 ? 'ml-2' : '' }}">{{ __('Referenced by:') }}</span>
                        @foreach($foreignKeys['incoming'] as $fk)
                            <a
                                href="{{ route('admin.system.database-tables.show', $fk['table']) }}"
                                wire:navigate
                                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full border border-border-default bg-surface-card text-link hover:bg-surface-subtle transition-colors font-mono"
                                title="{{ $fk['table'] }}.{{ $fk['column'] }} → {{ $fk['local_column'] }}"
                            >
                                <x-icon name="heroicon-o-arrow-uturn-left" class="w-3 h-3" />
                                {{ $fk['table'] }}
                            </a>
                        @endforeach
                    @endif
                </div>
            @endif

            <x-ui.card>
                <div class="mb-2 flex items-center justify-between gap-4">
                    <div class="flex-1">
                        <x-ui.search-input
                            wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Search across text columns...') }}"
                        />
                    </div>
                    @if(app()->environment('local') && $tableRegistry)
                        <button
                            wire:click="toggleStability"
                            class="cursor-pointer"
                            title="{{ $tableRegistry->is_stable ? __('Click to mark unstable') : __('Click to mark stable') }}"
                        >
                            <x-ui.badge :variant="$this->stabilityVariant($tableRegistry->is_stable)">
                                {{ $tableRegistry->is_stable ? __('Stable') : __('Unstable') }}
                            </x-ui.badge>
                        </button>
                    @endif
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
                    <x-ui.button
                        variant="ghost"
                        size="sm"
                        wire:click="toggleRawValues"
                        @class(['ring-2 ring-accent' => $this->rawValues])
                        aria-pressed="{{ $this->rawValues ? 'true' : 'false' }}"
                        title="{{ __('Toggle between formatted display and raw database values for NULL and boolean columns.') }}"
                        aria-label="{{ __('Toggle between formatted display and raw database values for NULL and boolean columns.') }}"
                    >
                        <x-icon name="heroicon-o-code-bracket" class="w-4 h-4" />
                        {{ $this->rawValues ? __('Raw') : __('Formatted') }}
                    </x-ui.button>
                    <span class="text-xs text-muted whitespace-nowrap tabular-nums">
                        {{ trans_choice(':count column|:count columns', count($columns), ['count' => count($columns)]) }}
                    </span>
                </div>

                <div class="overflow-x-auto -mx-card-inner px-card-inner">
                    <table class="min-w-full divide-y divide-border-default text-sm">
                        <thead class="bg-surface-subtle/80">
                            <tr>
                                @foreach($columns as $col)
                                    @php
                                        $outgoingFk = collect($foreignKeys['outgoing'])->firstWhere('column', $col['name']);
                                    @endphp
                                    <th
                                        wire:click="sort('{{ $col['name'] }}')"
                                        class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider cursor-pointer select-none hover:text-ink transition-colors"
                                        title="{{ $col['type_name'] }}{{ $col['nullable'] ? ', nullable' : '' }}{{ $outgoingFk ? ' → ' . $outgoingFk['foreign_table'] . '.' . $outgoingFk['foreign_column'] : '' }}"
                                    >
                                        <span class="inline-flex items-center gap-1">
                                            {{ $col['name'] }}
                                            @if($outgoingFk)
                                                <a
                                                    href="{{ route('admin.system.database-tables.show', $outgoingFk['foreign_table']) }}"
                                                    wire:navigate
                                                    class="text-accent hover:text-accent-hover"
                                                    title="{{ __('Go to :table', ['table' => $outgoingFk['foreign_table']]) }}"
                                                    onclick="event.stopPropagation()"
                                                >
                                                    <x-icon name="heroicon-o-link" class="w-3 h-3" />
                                                </a>
                                            @endif
                                            @if($this->sortColumn === $col['name'])
                                                @if($this->sortDirection === 'asc')
                                                    <x-icon name="heroicon-m-chevron-up" class="w-3 h-3" />
                                                @else
                                                    <x-icon name="heroicon-m-chevron-down" class="w-3 h-3" />
                                                @endif
                                            @endif
                                        </span>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="bg-surface-card divide-y divide-border-default">
                            @forelse($rows as $row)
                                <tr wire:key="row-{{ $loop->index }}" class="hover:bg-surface-subtle/50 transition-colors">
                                    @foreach($columns as $col)
                                        @php
                                            $value = data_get((array) $row, $col['name']);
                                            $formatted = $this->formatCell($value, $col['type_name']);
                                            $isLong = $value !== null && mb_strlen((string) $value) > 120;
                                            $outgoingFk = collect($foreignKeys['outgoing'])->firstWhere('column', $col['name']);
                                            $isTimestamp = $value !== null && (
                                                str_contains(strtolower($col['type_name']), 'timestamp')
                                                || str_contains(strtolower($col['type_name']), 'datetime')
                                            );
                                        @endphp
                                        <td
                                            class="px-table-cell-x py-table-cell-y text-sm font-mono whitespace-nowrap {{ $value === null ? 'text-muted' : 'text-ink' }}"
                                            @if($isLong) title="{{ Str::limit((string) $value, 500) }}" @endif
                                            @if($isTimestamp)
                                                x-data
                                                x-effect="
                                                    if (localTime) {
                                                        const el = $el;
                                                        const text = el.getAttribute('data-raw') || el.textContent;
                                                        if (!el.getAttribute('data-raw')) el.setAttribute('data-raw', text);
                                                        try { el.textContent = new Date(text.trim()).toLocaleString(); } catch(e) {}
                                                    } else {
                                                        const raw = $el.getAttribute('data-raw');
                                                        if (raw) $el.textContent = raw;
                                                    }
                                                "
                                            @endif
                                        >
                                            @if($outgoingFk && $value !== null)
                                                <a
                                                    href="{{ route('admin.system.database-tables.show', $outgoingFk['foreign_table']) }}?search={{ urlencode((string) $value) }}"
                                                    wire:navigate
                                                    class="text-link hover:underline"
                                                    title="{{ __('View in :table', ['table' => $outgoingFk['foreign_table']]) }}"
                                                >{{ $formatted }}</a>
                                            @else
                                                {{ $formatted }}
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ count($columns) }}" class="px-table-cell-x py-8 text-center text-sm text-muted">
                                        {{ $this->search ? __('No rows match your search.') : __('This table is empty.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-2">
                    {{ $rows->links() }}
                </div>
            </x-ui.card>
        </div>
    </div>
</div>
