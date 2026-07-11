<?php

use App\Base\Database\Livewire\DatabaseTables\Show;

/** @var Show $this */
?>

<div
    x-data="{
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
                    aria-label="{{ __('Filter tables') }}"
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

        <div>
            <x-ui.page-header
                :title="$this->tableName"
                :subtitle="trans_choice(':count row|:count rows', $rowCount, ['count' => app(\App\Base\Locale\Contracts\NumberDisplayService::class)->formatInteger($rowCount)])"
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

            @if($this->captureStatusMessage)
                <x-ui.alert :variant="$this->captureStatusVariant ?? 'info'" class="mt-4">
                    {{ $this->captureStatusMessage }}
                </x-ui.alert>
            @endif

            <div @class(['mt-4 space-y-section-gap' => count($this->orphanedRegistryNotices) > 0])>
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

                <x-ui.tabs
                    :tabs="[
                        ['id' => 'data', 'label' => __('Data'), 'icon' => 'heroicon-o-table-cells'],
                        ['id' => 'schema', 'label' => __('Schema'), 'icon' => 'heroicon-o-circle-stack'],
                        ['id' => 'relationships', 'label' => __('Relationships'), 'icon' => 'heroicon-o-link'],
                    ]"
                    default="data"
                    size="sm"
                >
                    <x-ui.tab id="data">
                        @include('livewire.admin.system.database-tables.partials.show-data-tab', [
                            'columns' => $columns,
                            'foreignKeys' => $foreignKeys,
                            'rowCount' => $rowCount,
                            'rows' => $rows,
                            'tableRegistry' => $tableRegistry,
                            'canCapture' => $canCapture,
                            'capturePrimaryKey' => $capturePrimaryKey,
                        ])
                    </x-ui.tab>

                    <x-ui.tab id="schema" class="space-y-4">
                        @include('livewire.admin.system.database-tables.partials.show-schema-tab', [
                            'columns' => $columns,
                            'foreignKeys' => $foreignKeys,
                            'indexes' => $indexes,
                            'migrationSource' => $migrationSource,
                            'tableRegistry' => $tableRegistry,
                        ])
                    </x-ui.tab>

                    <x-ui.tab id="relationships" class="space-y-4">
                        @include('livewire.admin.system.database-tables.partials.show-relationships-tab', [
                            'foreignKeys' => $foreignKeys,
                        ])
                    </x-ui.tab>
                </x-ui.tabs>
            </div>
        </div>
    </div>

    @if($canCapture)
        <x-ui.modal wire:model="showCaptureModal" class="max-w-2xl">
            <div class="p-card-inner space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-medium tracking-tight text-ink">
                            {{ __('Review Data Share capture') }}
                        </h2>
                        <p class="mt-1 text-sm text-muted">
                            {{ trans_choice(':count selected row from :table. String values retain their exact bytes, and only a development instance can import the package.|:count selected rows from :table. String values retain their exact bytes, and only a development instance can import the package.', $this->capturePreview['selected_rows'] ?? 0, ['count' => $this->capturePreview['selected_rows'] ?? 0, 'table' => $this->tableName]) }}
                        </p>
                    </div>
                    <x-ui.badge variant="warning">{{ __('Development only') }}</x-ui.badge>
                </div>

                @if($this->capturePreview !== null)
                    <div>
                        <p class="text-[11px] uppercase tracking-wider font-semibold text-muted">
                            {{ __('Dependency closure — :selected selected + :referenced referenced rows', [
                                'selected' => $this->capturePreview['selected_rows'],
                                'referenced' => max(0, $this->capturePreview['total_rows'] - $this->capturePreview['selected_rows']),
                            ]) }}
                        </p>
                        <x-ui.table container="flush" :caption="__('Tables included in the capture')" class="mt-2">
                            <x-slot name="head">
                                <tr>
                                    <x-ui.th>{{ __('Table') }}</x-ui.th>
                                    <x-ui.th class="text-right">{{ __('Rows') }}</x-ui.th>
                                    <x-ui.th>{{ __('Redacted columns') }}</x-ui.th>
                                </tr>
                            </x-slot>
                            @foreach($this->capturePreview['tables'] as $entry)
                                <tr wire:key="capture-{{ $entry['table'] }}">
                                    <td class="px-table-cell-x py-table-cell-y text-sm font-mono text-ink">{{ $entry['table'] }}</td>
                                    <td class="px-table-cell-x py-table-cell-y text-sm text-ink text-right tabular-nums">{{ $entry['row_count'] }}</td>
                                    <td class="px-table-cell-x py-table-cell-y text-sm">
                                        @if($entry['redacted_columns'] === [])
                                            <span class="text-muted">—</span>
                                        @else
                                            <span class="text-status-danger font-mono">{{ implode(', ', $entry['redacted_columns']) }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                        <p class="mt-2 text-xs text-muted">
                            {{ __('Source: :env environment, :driver driver, :encoding encoding. Estimated payload: :size bytes. The package records this provenance because encoding bugs may only reproduce on the same driver.', [
                                'env' => $this->capturePreview['source']['app_env'] ?? '',
                                'driver' => $this->capturePreview['source']['driver'] ?? '',
                                'encoding' => $this->capturePreview['source']['encoding'] ?? __('unknown'),
                                'size' => app(\App\Base\Locale\Contracts\NumberDisplayService::class)->formatInteger($this->capturePreview['payload_size_bytes']),
                            ]) }}
                        </p>
                    </div>

                    <x-ui.alert variant="warning">
                        {{ __('The package is written unencrypted to this machine\'s protected storage. DataShare rules and ciphertext detection redact identified secrets, but remaining values are raw and may still be sensitive — transfer it only over a trusted channel.') }}
                    </x-ui.alert>
                @endif

                <div class="flex items-center justify-end gap-2">
                    <x-ui.button variant="ghost" wire:click="closeCaptureDialog">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button wire:click="createCapturePackage" wire:loading.attr="disabled">
                        <x-icon name="heroicon-o-arrow-up-tray" class="w-4 h-4" />
                        {{ __('Create package') }}
                    </x-ui.button>
                </div>
            </div>
        </x-ui.modal>
    @endif
</div>
