<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var list<array<string, mixed>> $timeline */
$controlPlaneContext = request()->only(['from', 'returnTo']);
?>
<div class="space-y-1">
    @forelse ($timeline as $entry)
        @php
            $isMeta = $entry['source'] === 'meta';
            $sourceBadgeClass = $isMeta
                ? 'bg-surface-subtle text-muted ring-1 ring-border-default'
                : 'bg-accent text-accent-on';
            $sourceLabel = $isMeta ? __('META') : __('WIRE');
        @endphp
        <div class="rounded-lg border border-border-default bg-surface-card px-3 py-2">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0 space-y-1">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="inline-flex items-center rounded px-1 py-px text-[9px] font-bold uppercase tracking-wider {{ $sourceBadgeClass }}">
                            {{ $sourceLabel }}
                        </span>
                        <x-ui.badge :variant="$entry['severity']">{{ $entry['label'] }}</x-ui.badge>

                        @if ($isMeta && $entry['seq'] !== null)
                            <span class="font-mono text-[11px] text-muted">#{{ $entry['seq'] }}</span>
                        @endif

                        @if (! $isMeta && $entry['entry_number'] !== null)
                            <span class="font-mono text-[11px] text-muted">{{ __('entry :number', ['number' => $entry['entry_number']]) }}</span>
                        @endif

                        @if ($isMeta && ($entry['has_gap_warning'] || $entry['is_stuck']))
                            @if ($entry['has_gap_warning'])
                                <x-ui.badge variant="warning">
                                    {{ __('Gap :sec s', ['sec' => number_format(($entry['gap_ms'] ?? 0) / 1000, 1)]) }}
                                </x-ui.badge>
                            @endif
                            @if ($entry['is_stuck'])
                                <x-ui.badge variant="danger">{{ __('Stuck') }}</x-ui.badge>
                            @endif
                        @endif
                    </div>

                    <p class="text-[11px] text-ink">{{ $entry['summary'] !== '' ? $entry['summary'] : __('No summary.') }}</p>
                </div>

                <div class="shrink-0">
                    <x-ui.datetime :value="$entry['timestamp'] !== '' ? $entry['timestamp'] : null" class="text-[11px] text-muted tabular-nums" />
                </div>
            </div>

            @if (! empty($entry['payload']))
                <details class="mt-1.5">
                    <summary class="cursor-pointer text-[11px] font-medium text-accent hover:underline">
                        {{ __('Payload') }}
                    </summary>
                    <pre class="mt-1 overflow-x-auto rounded-lg bg-surface-subtle p-2 text-[10px] text-muted leading-relaxed">{{ json_encode($entry['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                </details>
            @endif
        </div>
    @empty
        <x-ui.alert variant="info">{{ __('No timeline events were recorded for this run.') }}</x-ui.alert>
    @endforelse
</div>
