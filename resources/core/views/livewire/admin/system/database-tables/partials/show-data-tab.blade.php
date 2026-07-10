<x-ui.card>
    @php
        $localeContext = app(\App\Base\Locale\Contracts\LocaleContext::class);
        $dateTimes = app(\App\Base\DateTime\Contracts\DateTimeDisplayService::class);
        $companyTimezone = $dateTimes->currentCompanyTimezone();
    @endphp

    <div class="mb-2 flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
        <div class="flex-1">
            <x-ui.search-input
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search across text columns...') }}"
            />
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if($tableRegistry)
                <x-ui.badge :variant="$this->schemaStateVariant($schemaState)">
                    {{ Str::headline($schemaState) }}
                </x-ui.badge>
            @endif
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
    </div>

    @if($canCapture && count($this->selectedRowIds) > 0)
        <div class="mb-2 flex flex-wrap items-center gap-2 rounded-lg border border-border-default bg-surface-subtle px-3 py-2">
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.badge variant="info">{{ __('Data Bridge') }}</x-ui.badge>
                <span class="text-sm text-ink tabular-nums">
                    {{ trans_choice(':count row selected|:count rows selected', count($this->selectedRowIds), ['count' => count($this->selectedRowIds)]) }}
                </span>
            </div>
            <div class="ml-auto flex items-center gap-2">
                <x-ui.button variant="ghost" size="sm" wire:click="clearSelection">
                    {{ __('Clear') }}
                </x-ui.button>
                <x-ui.button size="sm" wire:click="openCaptureDialog">
                    <x-icon name="heroicon-o-archive-box" class="w-4 h-4" />
                    {{ __('Preview package…') }}
                </x-ui.button>
            </div>
        </div>
    @endif

    <x-ui.table container="flush" :caption="__('Table data')">

            <x-slot name="head">
                <tr>
                    @if($canCapture)
                        <x-ui.th class="w-8">
                            <span class="sr-only">{{ __('Select rows') }}</span>
                        </x-ui.th>
                    @endif
                    @foreach($columns as $col)
                        @php
                            $outgoingFk = collect($foreignKeys['outgoing'])->firstWhere('column', $col['name']);
                        @endphp
                        <x-ui.sortable-th
                            :column="$col['name']"
                            :sort-by="$this->sortColumn"
                            :sort-dir="$this->sortDirection"
                            :label="$col['name']"
                            :title="$col['type_name']
                                .($col['nullable'] ? ', nullable' : '')
                                .($outgoingFk ? ' → '.$outgoingFk['foreign_table'].'.'.$outgoingFk['foreign_column'] : '')"
                        >
                            @if($outgoingFk)
                                <x-slot:after>
                                    <a
                                        href="{{ route('admin.system.database-tables.show', $outgoingFk['foreign_table']) }}"
                                        wire:navigate
                                        class="text-accent hover:text-accent-hover"
                                        title="{{ __('Go to :table', ['table' => $outgoingFk['foreign_table']]) }}"
                                    >
                                        <x-icon name="heroicon-o-link" class="w-3 h-3" />
                                    </a>
                                </x-slot:after>
                            @endif
                        </x-ui.sortable-th>
                    @endforeach
                </tr>
            </x-slot>

                @forelse($rows as $row)
                    <tr wire:key="{{ $this->rowKey($row, $loop->index) }}">
                        @if($canCapture)
                            @php $captureId = (string) data_get((array) $row, $capturePrimaryKey); @endphp
                            <td class="px-table-cell-x py-table-cell-y w-8">
                                <x-ui.checkbox
                                    id="capture-row-{{ $captureId }}"
                                    value="{{ $captureId }}"
                                    wire:model.live="selectedRowIds"
                                    aria-label="{{ __('Select row :id', ['id' => $captureId]) }}"
                                />
                            </td>
                        @endif
                        @foreach($columns as $col)
                            @php
                                $value = data_get((array) $row, $col['name']);
                                $formatted = $this->formatCell($value, $col['type_name']);
                                $isLong = $value !== null && mb_strlen((string) $value) > 120;
                                $outgoingFk = collect($foreignKeys['outgoing'])->firstWhere('column', $col['name']);
                                $isTimestamp = $value !== null && $this->isTimestampType($col['type_name']);
                                $timestampIso = $isTimestamp ? $this->timestampIso($value) : null;
                            @endphp
                            <td
                                class="px-table-cell-x py-table-cell-y text-sm font-mono whitespace-nowrap {{ $value === null ? 'text-muted' : 'text-ink' }}"
                                @if($isLong) title="{{ Str::limit((string) $value, 500) }}" @endif
                            >
                                @if($outgoingFk && $value !== null)
                                    <a
                                        href="{{ route('admin.system.database-tables.show', $outgoingFk['foreign_table']) }}?search={{ urlencode((string) $value) }}"
                                        wire:navigate
                                        class="text-link hover:underline"
                                        title="{{ __('View in :table', ['table' => $outgoingFk['foreign_table']]) }}"
                                    >
                                        @if($isTimestamp && $timestampIso !== null)
                                            <time
                                                datetime="{{ $timestampIso }}"
                                                data-format="datetime"
                                                data-locale="{{ $localeContext->forIntl() }}"
                                                data-company-timezone="{{ $companyTimezone }}"
                                                data-raw-text="{{ (string) $value }}"
                                                x-data
                                                x-init="
                                                    const apply = () => {
                                                        if (window.blbMountDateTimeElement) {
                                                            window.blbMountDateTimeElement($el, () => ({}));
                                                            return;
                                                        }

                                                        requestAnimationFrame(apply);
                                                    };

                                                    apply();
                                                "
                                                x-effect="window.blbFormatDateTimeElement?.($el)"
                                            >{{ $formatted }}</time>
                                        @else
                                            {{ $formatted }}
                                        @endif
                                    </a>
                                @else
                                    @if($isTimestamp && $timestampIso !== null)
                                        <time
                                            datetime="{{ $timestampIso }}"
                                            data-format="datetime"
                                            data-locale="{{ $localeContext->forIntl() }}"
                                            data-company-timezone="{{ $companyTimezone }}"
                                            data-raw-text="{{ (string) $value }}"
                                            x-data
                                            x-init="
                                                const apply = () => {
                                                    if (window.blbMountDateTimeElement) {
                                                        window.blbMountDateTimeElement($el, () => ({}));
                                                        return;
                                                    }

                                                    requestAnimationFrame(apply);
                                                };

                                                apply();
                                            "
                                            x-effect="window.blbFormatDateTimeElement?.($el)"
                                        >{{ $formatted }}</time>
                                    @else
                                        {{ $formatted }}
                                    @endif
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($columns) + ($canCapture ? 1 : 0) }}" class="px-table-cell-x py-8 text-center text-sm text-muted">
                            {{ $this->search ? __('No rows match your search.') : __('This table is empty.') }}
                        </td>
                    </tr>
                @endforelse


    </x-ui.table>

    <div class="mt-2">
        {{ $rows->links() }}
    </div>
</x-ui.card>
