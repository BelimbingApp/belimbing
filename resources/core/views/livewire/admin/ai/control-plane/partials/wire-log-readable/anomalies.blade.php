<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var list<array<string, mixed>> $anomalies */
/** @var string $runId */
?>
<details class="group" open>
    <summary class="flex cursor-pointer list-none items-start gap-2 [&::-webkit-details-marker]:hidden">
        <x-icon name="heroicon-o-exclamation-triangle" class="mt-0.5 size-4 shrink-0 text-status-warning" />
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-status-warning">
                    {{ __('Anomaly signals') }}
                </p>
                <x-ui.badge variant="warning">
                    <span class="inline-flex items-center gap-1">
                        <span>{{ count($anomalies) }}</span>
                        <x-icon name="heroicon-m-chevron-down" class="size-3 transition-transform group-open:rotate-180" />
                    </span>
                </x-ui.badge>
            </div>
        </div>
    </summary>

    <ul class="mt-1 space-y-1 pl-6">
        @foreach ($anomalies as $anomaly)
            <li class="flex flex-wrap items-baseline gap-2 text-xs">
                <x-ui.badge :variant="$anomaly['severity'] ?? 'warning'">{{ $anomaly['label'] }}</x-ui.badge>
                <span class="text-ink">{{ $anomaly['detail'] }}</span>
                @if (! empty($anomaly['entry_numbers']))
                    <span class="text-muted">
                        ({{ __('entries:') }}
                        @foreach (array_slice($anomaly['entry_numbers'], 0, 5) as $entryNumber)
                            <button
                                type="button"
                                class="text-accent hover:underline"
                                @click.prevent="window.dispatchEvent(new CustomEvent('wire-log-focus-entry', { detail: { entryNumber: {{ (int) $entryNumber }} } }))"
                            >#{{ $entryNumber }}</button>{{ ! $loop->last ? ', ' : '' }}
                        @endforeach
                        @if (count($anomaly['entry_numbers']) > 5)
                            +{{ count($anomaly['entry_numbers']) - 5 }} {{ __('more') }}
                        @endif
                        )
                    </span>
                @endif
            </li>
        @endforeach
    </ul>
</details>
