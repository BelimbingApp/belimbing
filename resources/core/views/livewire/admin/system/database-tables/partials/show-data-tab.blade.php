<x-ui.card>
    @php
        $localeContext = app(\App\Base\Locale\Contracts\LocaleContext::class);
        $dateTimes = app(\App\Base\DateTime\Contracts\DateTimeDisplayService::class);
        $currentTzMode = $dateTimes->currentMode();
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
                @click="
                    const modes = ['utc', 'company', 'local'];
                    const idx = modes.indexOf(tableTzMode);
                    tableTzMode = modes[(idx + 1) % modes.length];
                "
                ::class="tableTzMode !== 'utc' ? 'ring-2 ring-accent' : ''"
                x-bind:aria-pressed="(tableTzMode !== 'utc').toString()"
                title="{{ __('Cycle timestamp display: UTC → Company → Local.') }}"
                aria-label="{{ __('Cycle timestamp display mode.') }}"
            >
                <x-icon name="heroicon-o-clock" class="w-4 h-4" />
                <span x-text="tableTzMode === 'utc' ? '{{ __('UTC') }}' : (tableTzMode === 'company' ? '{{ __('Company') }}' : '{{ __('Local') }}')"></span>
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
                    <tr wire:key="{{ $this->rowKey($row, $loop->index) }}" class="hover:bg-surface-subtle/50 transition-colors">
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
                                                            window.blbMountDateTimeElement($el, () => ({ mode: tableTzMode }));
                                                            return;
                                                        }

                                                        requestAnimationFrame(apply);
                                                    };

                                                    apply();
                                                "
                                                x-effect="window.blbFormatDateTimeElement?.($el, { mode: tableTzMode })"
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
                                                        window.blbMountDateTimeElement($el, () => ({ mode: tableTzMode }));
                                                        return;
                                                    }

                                                    requestAnimationFrame(apply);
                                                };

                                                apply();
                                            "
                                            x-effect="window.blbFormatDateTimeElement?.($el, { mode: tableTzMode })"
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
