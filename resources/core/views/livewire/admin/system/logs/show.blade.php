<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Base\Log\Livewire\Logs\Show $this */
?>

<div x-data="{ localTime: false }">
    <x-slot name="title">{{ $this->filename }}</x-slot>

    @php
        $deleteLinesCount = $this->deleteLines > 0 ? $this->deleteLines : 10;
        $windowLabelStart = $windowEnd > 0 ? $windowStart + 1 : 0;
        $nextLabel = $this->mode === 'top' ? __('Next') : __('Older');
        $nextTooltip = $this->mode === 'top'
            ? __('Show the next chunk of lines further into the file.')
            : __('Show an older chunk of lines.');
    @endphp

    <div class="space-y-section-gap">
        {{-- Header --}}
        <x-ui.page-header :title="$this->filename" :subtitle="__(':size · :lines lines', ['size' => Number::fileSize($fileSize), 'lines' => number_format($totalLines)])">
            <x-slot name="actions">
                <a href="{{ route('admin.system.logs.index') }}" class="text-accent hover:underline text-sm" wire:navigate>
                    ← {{ __('Back to Logs') }}
                </a>
            </x-slot>
        </x-ui.page-header>

        {{-- Toolbar --}}
        <x-ui.card>
            <div class="flex flex-wrap items-end gap-3">
                {{-- Lines per chunk --}}
                <div class="flex flex-col gap-1">
                    <label for="lines-input" class="text-[11px] uppercase tracking-wider font-semibold text-muted">
                        {{ __($this->mode === 'top' ? 'Top :count lines' : 'Tail :count lines', ['count' => number_format($this->lines)]) }}
                    </label>
                    <x-ui.input
                        type="number"
                        id="lines-input"
                        wire:model.live.debounce.500ms="lines"
                        min="1"
                        max="1000"
                        class="w-24 py-1! text-xs"
                    />
                </div>

                {{-- Search --}}
                <div class="flex flex-col gap-1 flex-1 min-w-48">
                    <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Search') }}</span>
                    <x-ui.search-input
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Filter lines...') }}"
                    />
                </div>

                {{-- Delete Lines From Top --}}
                <div class="flex flex-col gap-1">
                    <x-ui.button
                        variant="danger-ghost"
                        size="sm"
                        wire:click="deleteLinesFromTop"
                        wire:confirm="{{ __('Delete :count lines from the top of this file?', ['count' => number_format($deleteLinesCount)]) }}"
                    >
                        <x-icon name="heroicon-o-scissors" class="w-4 h-4" />
                        {{ __('Delete :count lines from top', ['count' => number_format($deleteLinesCount)]) }}
                    </x-ui.button>
                    <div class="flex items-center gap-1">
                        <x-ui.input
                            type="number"
                            id="delete-lines-input"
                            wire:model.live.debounce.200ms="deleteLines"
                            min="0"
                            class="w-24 py-1! text-xs"
                        />
                    </div>
                </div>

                {{-- Time Toggle --}}
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

                {{-- Refresh --}}
                <x-ui.button
                    variant="ghost"
                    size="sm"
                    wire:click="refresh"
                    title="{{ __('Reload log content from disk.') }}"
                    aria-label="{{ __('Reload log content from disk.') }}"
                >
                    <x-icon name="heroicon-o-arrow-path" class="w-4 h-4" wire:loading.class="animate-spin" wire:target="refresh" />
                    {{ __('Refresh') }}
                </x-ui.button>

                {{-- Delete File --}}
                <x-ui.button
                    variant="danger-ghost"
                    size="sm"
                    wire:click="deleteFile"
                    wire:confirm="{{ __('Permanently delete this log file?') }}"
                    title="{{ __('Permanently delete this log file.') }}"
                    aria-label="{{ __('Permanently delete this log file.') }}"
                >
                    <x-icon name="heroicon-o-trash" class="w-4 h-4" />
                    {{ __('Delete') }}
                </x-ui.button>
            </div>
        </x-ui.card>

        {{-- Status & Navigation Bar --}}
        <div class="flex flex-wrap items-center justify-between gap-2">
            {{-- Status --}}
            <div class="flex items-center gap-3 text-xs text-muted">
                <span>{{ __('Showing :displayed of :total lines', ['displayed' => number_format($displayedCount), 'total' => number_format($totalLines)]) }}</span>
                <span>· {{ __('lines :start–:end', ['start' => number_format($windowLabelStart), 'end' => number_format($windowEnd)]) }}</span>
                @if($search)
                    <span>· {{ __('filtered by ":search"', ['search' => $search]) }}</span>
                @endif
            </div>

            {{-- Page Navigation --}}
            <div class="flex items-center gap-1">
                <x-ui.button
                    variant="{{ $this->mode === 'top' ? 'outline' : 'ghost' }}"
                    size="xs"
                    wire:click="switchMode('top')"
                    title="{{ __('Show the first chunk of lines from the file.') }}"
                >
                    <x-icon name="heroicon-m-arrow-up" class="w-3.5 h-3.5" />
                    {{ __('Top') }}
                </x-ui.button>

                <x-ui.button
                    variant="{{ $this->mode === 'tail' ? 'outline' : 'ghost' }}"
                    size="xs"
                    wire:click="switchMode('tail')"
                    title="{{ __('Show the latest chunk of lines from the file.') }}"
                >
                    <x-icon name="heroicon-o-arrow-down-tray" class="w-3.5 h-3.5" />
                    {{ __('Tail') }}
                </x-ui.button>

                <span class="text-border-default mx-0.5">|</span>

                <x-ui.button
                    variant="ghost"
                    size="xs"
                    wire:click="nextWindow"
                    :disabled="!$hasMore"
                    title="{{ $nextTooltip }}"
                >
                    <x-icon name="{{ $this->mode === 'top' ? 'heroicon-o-chevron-down' : 'heroicon-o-chevron-up' }}" class="w-3.5 h-3.5" />
                    {{ $nextLabel }}
                </x-ui.button>

                <span class="text-xs tabular-nums text-muted">{{ $this->window + 1 }}/{{ max($totalWindows, 1) }}</span>
            </div>
        </div>

        {{-- Log Content --}}
        <x-ui.card>
            <div class="overflow-x-auto -mx-card-inner">
                @if(count($logLines) > 0)
                    <table class="min-w-full text-xs font-mono">
                        <tbody>
                            @foreach($logLines as $line)
                                <tr wire:key="line-{{ $line['number'] }}" class="hover:bg-surface-subtle/50 group border-b border-border-default/30 last:border-b-0">
                                    <td class="px-3 py-0.5 text-right text-muted select-none w-1 whitespace-nowrap tabular-nums align-top">{{ $line['number'] }}</td>
                                    <td
                                        class="px-3 py-0.5 text-ink whitespace-pre-wrap break-all"
                                        x-data
                                        x-effect="
                                            if (localTime) {
                                                const el = $el;
                                                const text = el.getAttribute('data-raw') || el.textContent;
                                                if (!el.getAttribute('data-raw')) el.setAttribute('data-raw', text);
                                                el.textContent = text.replace(/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})?/g, (m) => {
                                                    try { return new Date(m).toLocaleString(); } catch(e) { return m; }
                                                });
                                            } else {
                                                const raw = $el.getAttribute('data-raw');
                                                if (raw) $el.textContent = raw;
                                            }
                                        "
                                    >{{ $line['content'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="px-card-inner py-8 text-center text-sm text-muted">
                        {{ $totalLines === 0 ? __('Log file is empty.') : __('No lines match the current filter.') }}
                    </div>
                @endif
            </div>
        </x-ui.card>
    </div>
</div>
