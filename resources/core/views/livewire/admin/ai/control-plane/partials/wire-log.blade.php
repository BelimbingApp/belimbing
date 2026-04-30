<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var list<array<string, mixed>> $entries */
/** @var array{footprint_bytes: int, total_entries: int, visible_entries: int, offset: int, limit: int, range_start: int, range_end: int, omitted_before: int, omitted_after: int, has_previous: bool, has_next: bool, last_offset: int}|null $summary */
/** @var array<string, mixed> $readable */
/** @var string $runId */
$anomalies = $readable['anomalies'] ?? [];
?>
<div
    class="space-y-3"
    x-data="{
        mode: 'readable',
        init() {
            this.$nextTick(() => this.setMode(this.mode));
        },
        setMode(nextMode) {
            if (typeof $wire === 'undefined') {
                this.$nextTick(() => this.setMode(nextMode));
                return;
            }

            this.mode = nextMode;

            let currentLimit = Number($wire.wireLogLimit ?? 0);
            if (!Number.isFinite(currentLimit)) {
                currentLimit = 0;
            }
            let nextLimit = currentLimit;

            if (nextMode === 'readable' && currentLimit < 500) {
                nextLimit = 500;
            }

            if (nextMode === 'raw' && currentLimit > 1000) {
                nextLimit = 1000;
            }

            if (nextLimit !== currentLimit) {
                $wire.set('wireLogLimit', nextLimit);
            }
        },
        focusEntry(entryNumber) {
            if (!entryNumber) return;

            const id = 'wire-log-entry-' + entryNumber;
            const el = document.getElementById(id);

            // Ensure the anchor updates for shareability / refresh.
            if (window.location.hash !== '#' + id) {
                window.location.hash = id;
            }

            if (el && el.tagName && el.tagName.toLowerCase() === 'details') {
                el.open = true;
            }

            // Ask Alpine-powered fragments to open their inline payload panel.
            window.dispatchEvent(new CustomEvent('wire-log-open-entry', { detail: { entryNumber } }));

            // Scroll after DOM updates.
            this.$nextTick(() => {
                const target = document.getElementById(id);
                target?.scrollIntoView({ block: 'center' });
            });
        },
    }"
    x-on:wire-log-window-changed.window="$nextTick(() => {
        const scroll = $event.detail?.scrollWireLogPanelIntoView === true;
        if (scroll) {
            document.getElementById('wire-log-panel')?.scrollIntoView({ block: 'start' });
        }
    })"
    x-on:wire-log-focus-entry.window="focusEntry($event.detail.entryNumber)"
>
    @if (! $wireLoggingEnabled)
        <x-ui.alert variant="info">
            {{ __('Wire logging is disabled. Enable AI wire logging to capture raw transport requests, responses, and full tool payloads for future runs.') }}
        </x-ui.alert>
    @elseif ($entries === [])
        <x-ui.alert variant="info">
            {{ __('No wire log entries were retained for this run.') }}
        </x-ui.alert>
    @else
        <div
            class="sticky top-0 z-10 -mx-card-inner rounded-2xl border border-border-default bg-surface-subtle/95 px-card-inner py-card-inner shadow-sm backdrop-blur"
            @click="document.getElementById('wire-log-panel')?.scrollIntoView({ block: 'start' })"
        >
            {{-- z-10 keeps the pin above scrolling entries; translucent bg still hints at content underneath. --}}
            <div class="space-y-3">
                <div class="cursor-pointer space-y-1 text-xs text-muted">
                    <p class="font-medium text-ink">
                        {{ __('Showing entries :start-:end of :total retained wire-log entries.', [
                            'start' => $summary['range_start'] ?? 0,
                            'end' => $summary['range_end'] ?? 0,
                            'total' => $summary['total_entries'] ?? count($entries),
                        ]) }}
                        <span class="ml-1 text-muted" title="{{ __('Computed from this window only') }}">
                            <x-icon name="heroicon-m-information-circle" class="inline size-3.5" />
                        </span>
                    </p>
                    <p>
                        {{ __('This run retained :size on disk.', ['size' => \Illuminate\Support\Number::fileSize($summary['footprint_bytes'] ?? 0)]) }}
                    </p>
                    <p>
                        {{ __('Large entries can be opened raw in a separate tab without loading them into the inspector response.') }}
                    </p>
                </div>

                <div class="flex flex-wrap items-end gap-3" @click.stop>
                    <div class="flex items-end">
                        <div class="flex rounded-lg bg-surface-card p-1 shadow-sm ring-1 ring-border-default/50">
                            <button
                                type="button"
                                @click="setMode('readable')"
                                :class="mode === 'readable' ? 'bg-surface-subtle text-ink shadow-sm ring-1 ring-border-default/50' : 'text-muted hover:text-ink'"
                                class="rounded-md px-3 py-1.5 text-xs font-medium transition-all"
                            >
                                {{ __('Readable') }}
                            </button>
                            <button
                                type="button"
                                @click="setMode('raw')"
                                :class="mode === 'raw' ? 'bg-surface-subtle text-ink shadow-sm ring-1 ring-border-default/50' : 'text-muted hover:text-ink'"
                                class="rounded-md px-3 py-1.5 text-xs font-medium transition-all"
                            >
                                {{ __('Raw Entries') }}
                            </button>
                        </div>
                    </div>

                    <div x-show="mode === 'raw'" x-cloak class="w-[8rem] shrink-0">
                        <x-ui.select id="wire-log-limit-raw" wire:model.live="wireLogLimit" :label="__('Entries')">
                            @foreach ([100, 250, 500, 1000] as $limitOption)
                                <option value="{{ $limitOption }}">{{ $limitOption }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <div x-show="mode === 'readable'" x-cloak class="w-[10rem] shrink-0">
                        <x-ui.select id="wire-log-limit-readable" wire:model.live="wireLogLimit" :label="__('Entries')">
                            @foreach ([500, 1000, 1500, 2000] as $limitOption)
                                <option value="{{ $limitOption }}">{{ $limitOption }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <div class="w-[10rem] shrink-0 sm:w-[12rem]">
                        <x-ui.input
                            id="wire-log-start-entry"
                            wire:model.defer="wireLogStartEntry"
                            :label="__('Start At')"
                            type="number"
                            min="1"
                            max="{{ max(1, (int) ($summary['total_entries'] ?? count($entries))) }}"
                        />
                    </div>

                    <div class="flex min-w-0 flex-1 flex-wrap items-end gap-2">
                        <x-ui.button wire:click="jumpToWireLogEntry({{ $summary['total_entries'] ?? count($entries) }})" variant="secondary" size="sm">
                            {{ __('Go') }}
                        </x-ui.button>
                        <x-ui.button wire:click="firstWireLogEntries" variant="ghost" size="sm" :disabled="! ($summary['has_previous'] ?? false)">
                            {{ __('First') }}
                        </x-ui.button>
                        <x-ui.button wire:click="previousWireLogEntries" variant="ghost" size="sm" :disabled="! ($summary['has_previous'] ?? false)">
                            {{ __('Previous') }}
                        </x-ui.button>
                        <x-ui.button wire:click="nextWireLogEntries({{ $summary['range_end'] ?? 0 }})" variant="ghost" size="sm" :disabled="! ($summary['has_next'] ?? false)">
                            {{ __('Next') }}
                        </x-ui.button>
                        <x-ui.button wire:click="lastWireLogEntries({{ $summary['last_offset'] ?? 0 }})" variant="ghost" size="sm" :disabled="! ($summary['has_next'] ?? false)">
                            {{ __('Last') }}
                        </x-ui.button>
                    </div>
                </div>
            </div>

            @if (! empty($anomalies))
                <div class="mt-3 border-t border-border-default/60 pt-3" @click.stop>
                    @include('livewire.admin.ai.control-plane.partials.wire-log-readable.anomalies', [
                        'anomalies' => $anomalies,
                        'runId' => $runId,
                    ])
                </div>
            @endif
        </div>

        <div x-show="mode === 'readable'" x-cloak>
            @include('livewire.admin.ai.control-plane.partials.wire-log-readable', [
                'readable' => $readable,
                'runId' => $runId,
            ])
        </div>

        <div x-show="mode === 'raw'" x-cloak class="space-y-3">
            @foreach ($entries as $index => $entry)
            <details class="rounded-2xl border border-border-default bg-surface-card p-card-inner" @if ($index === 0) open @endif>
                <summary class="flex cursor-pointer flex-col gap-1 text-sm text-ink sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                    <span class="min-w-0">
                        <span class="font-medium">
                            {{ $entry['type'] ?? __('Unknown entry') }}
                            <span class="ml-2 text-xs font-normal text-muted">{{ __('#:number', ['number' => $entry['entry_number'] ?? (($summary['offset'] ?? 0) + $index + 1)]) }}</span>
                        </span>
                        <span class="ml-2 text-xs text-muted">{{ $entry['summary_preview'] ?? '{}' }}</span>
                    </span>
                    <x-ui.datetime :value="$entry['at'] ?? null" class="shrink-0 text-xs text-muted tabular-nums" />
                </summary>
                @if (($entry['preview_status'] ?? 'full') === 'line_omitted')
                    <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                        <p class="text-warning">{{ $entry['payload_pretty'] ?? '{}' }}</p>
                        <x-ui.button
                            as="a"
                            href="{{ route('admin.ai.runs.wire-log-entry', ['runId' => $runId, 'entryNumber' => $entry['entry_number'] ?? (($summary['offset'] ?? 0) + $index + 1)]) }}"
                            target="_blank"
                            rel="noreferrer"
                            variant="ghost"
                            size="sm"
                        >
                            {{ __('Open Raw') }}
                        </x-ui.button>
                    </div>
                @endif
                @if (($entry['preview_status'] ?? 'full') !== 'line_omitted')
                    <pre class="mt-3 overflow-x-auto rounded-2xl bg-surface-subtle p-3 text-[11px] text-muted">{{ $entry['payload_pretty'] ?? '{}' }}</pre>
                @endif
            </details>
        @endforeach
        </div>
    @endif
</div>
