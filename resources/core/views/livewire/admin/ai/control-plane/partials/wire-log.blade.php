<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var list<array<string, mixed>> $entries */
/** @var array{footprint_bytes: int, total_entries: int, visible_entries: int, offset: int, limit: int, range_start: int, range_end: int, omitted_before: int, omitted_after: int, has_previous: bool, has_next: bool, last_offset: int}|null $summary */
/** @var string $runId */
?>
<div
    class="space-y-3"
    x-data
    x-on:wire-log-window-changed.window="$nextTick(() => document.getElementById('wire-log-panel')?.scrollIntoView({ block: 'start' }))"
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
        <div class="flex flex-wrap items-end justify-between gap-3 rounded-2xl border border-border-default bg-surface-subtle p-card-inner">
            <div class="space-y-1 text-xs text-muted">
                <p class="font-medium text-ink">
                    {{ __('Showing entries :start-:end of :total retained wire-log entries.', [
                        'start' => $summary['range_start'] ?? 0,
                        'end' => $summary['range_end'] ?? 0,
                        'total' => $summary['total_entries'] ?? count($entries),
                    ]) }}
                </p>
                <p>
                    {{ __('This run retained :size on disk.', ['size' => \Illuminate\Support\Number::fileSize($summary['footprint_bytes'] ?? 0)]) }}
                </p>
                <p>
                    {{ __('Large entries can be opened raw in a separate tab without loading them into the inspector response.') }}
                </p>
            </div>

            <div class="grid gap-3 md:grid-cols-[8rem_10rem_auto]">
                <x-ui.select id="wire-log-limit" wire:model.live="wireLogLimit" :label="__('Entries')">
                    @foreach ([25, 50, 100, 250] as $limitOption)
                        <option value="{{ $limitOption }}">{{ $limitOption }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.input
                    id="wire-log-start-entry"
                    wire:model.defer="wireLogStartEntry"
                    :label="__('Start At')"
                    type="number"
                    min="1"
                    max="{{ max(1, (int) ($summary['total_entries'] ?? count($entries))) }}"
                />

                <div class="flex flex-wrap items-end gap-2">
                    <x-ui.button wire:click="jumpToWireLogEntry({{ $summary['total_entries'] ?? count($entries) }})" variant="secondary" size="sm">
                        {{ __('Go') }}
                    </x-ui.button>
                    <x-ui.button wire:click="firstWireLogEntries" variant="ghost" size="sm" :disabled="! ($summary['has_previous'] ?? false)">
                        {{ __('First') }}
                    </x-ui.button>
                    <x-ui.button wire:click="previousWireLogEntries" variant="ghost" size="sm" :disabled="! ($summary['has_previous'] ?? false)">
                        {{ __('Previous') }}
                    </x-ui.button>
                    <x-ui.button wire:click="nextWireLogEntries" variant="ghost" size="sm" :disabled="! ($summary['has_next'] ?? false)">
                        {{ __('Next') }}
                    </x-ui.button>
                    <x-ui.button wire:click="lastWireLogEntries({{ $summary['last_offset'] ?? 0 }})" variant="ghost" size="sm" :disabled="! ($summary['has_next'] ?? false)">
                        {{ __('Last') }}
                    </x-ui.button>
                </div>
            </div>
        </div>

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
    @endif
</div>
