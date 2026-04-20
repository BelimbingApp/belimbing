<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var list<array<string, mixed>> $timeline */
$controlPlaneContext = request()->only(['from', 'returnTo']);
?>
<div class="space-y-3">
    @forelse ($timeline as $event)
        <div class="rounded-2xl border border-border-default bg-surface-card p-card-inner">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0 space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-ui.badge :variant="$event['severity']">{{ $event['label'] }}</x-ui.badge>
                        <span class="text-xs font-mono text-muted">#{{ $event['seq'] }}</span>
                        @if ($event['run_id'])
                            <a
                                href="{{ route('admin.ai.control-plane', array_merge($controlPlaneContext, ['tab' => 'inspector', 'runId' => $event['run_id']])) }}"
                                wire:navigate
                                class="text-xs text-accent hover:underline"
                            >
                                {{ __('Inspect Run') }}
                            </a>
                        @endif
                    </div>

                    <p class="text-sm text-ink">{{ $event['summary'] !== '' ? $event['summary'] : __('No summary available.') }}</p>

                    @if ($event['has_gap_warning'] || $event['is_stuck'])
                        <div class="flex flex-wrap gap-2">
                            @if ($event['has_gap_warning'])
                                <x-ui.badge variant="warning">
                                    {{ __('Gap: :seconds sec', ['seconds' => number_format(($event['gap_ms'] ?? 0) / 1000, 1)]) }}
                                </x-ui.badge>
                            @endif

                            @if ($event['is_stuck'])
                                <x-ui.badge variant="danger">{{ __('Potentially stuck') }}</x-ui.badge>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="shrink-0 text-xs text-muted tabular-nums">
                    {{ $event['created_at'] ?? '---' }}
                </div>
            </div>

            @if (! empty($event['payload']))
                <details class="mt-3">
                    <summary class="cursor-pointer text-xs font-medium text-accent hover:underline">
                        {{ __('View Payload') }}
                    </summary>
                    <pre class="mt-2 overflow-x-auto rounded-2xl bg-surface-subtle p-3 text-[11px] text-muted">{{ json_encode($event['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                </details>
            @endif
        </div>
    @empty
        <x-ui.alert variant="info">{{ __('No turn events were recorded for this turn.') }}</x-ui.alert>
    @endforelse
</div>
