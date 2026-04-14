<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
?>

@props([
    'tool',
    'argsSummary' => '{}',
    'status' => 'success',
    'durationMs' => null,
    'resultPreview' => '',
    'resultLength' => 0,
    'errorPayload' => null,
    'expanded' => false,
])

@php
    $statusBadge = match ($status) {
        'error' => ['variant' => 'danger', 'label' => __('Error')],
        'denied' => ['variant' => 'warning', 'label' => __('Denied')],
        'running' => ['variant' => 'info', 'label' => __('Running')],
        default => ['variant' => 'success', 'label' => __('Done')],
    };

    $hasDetail = $errorPayload !== null || $resultPreview !== '';
@endphp

<x-ai.activity.entry type="tool_call" :timestamp="now()">
    <div class="rounded-lg border border-border-default bg-surface-card">
        <div class="px-2.5 py-1.5 text-left text-xs">
            <div class="flex items-start gap-2">
                <span class="min-w-0 flex-1 font-medium text-ink break-words">{{ $tool }}</span>
                <span class="ml-auto flex items-center gap-1.5 shrink-0">
                    @if ($durationMs !== null)
                        <span class="tabular-nums text-muted">{{ number_format($durationMs / 1000, 1) }}s</span>
                    @endif
                    <x-ui.badge :variant="$statusBadge['variant']" class="text-[9px]">
                        {{ $statusBadge['label'] }}
                    </x-ui.badge>
                </span>
            </div>

            @if ($argsSummary !== '')
                <div @class([
                    'mt-1 text-muted whitespace-pre-wrap break-all font-mono text-[11px]',
                ])>{{ $argsSummary }}</div>
            @endif
        </div>

        @if ($hasDetail)
            <div class="border-t border-border-default px-2.5 py-2 text-xs space-y-1">
                @if ($errorPayload !== null)
                    <div class="flex items-center gap-2 text-red-500">
                        @if (isset($errorPayload['code']))
                            <span class="font-medium">{{ $errorPayload['code'] }}</span>
                        @endif
                        @if (isset($errorPayload['hint']))
                            <span class="text-muted">{{ $errorPayload['hint'] }}</span>
                        @endif
                    </div>
                @endif
                @if ($resultPreview !== '')
                    <div class="text-muted whitespace-pre-wrap break-words">{{ $resultPreview }}</div>
                    @if ($resultLength > 200)
                        <div class="text-muted/70 tabular-nums">{{ number_format($resultLength) }} {{ __('chars total') }}</div>
                    @endif
                @endif
            </div>
        @endif
    </div>
</x-ai.activity.entry>
