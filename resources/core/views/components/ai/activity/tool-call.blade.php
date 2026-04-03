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
@endphp

<x-ai.activity.entry type="tool_call" :timestamp="now()">
    <div x-data="{ expanded: @js($expanded) }" class="rounded-lg border border-border-default bg-surface-card">
        {{-- Header: always visible --}}
        <button
            type="button"
            @click="expanded = !expanded"
            class="w-full flex items-center gap-2 px-2.5 py-1.5 text-left text-xs hover:bg-surface-subtle/50 transition-colors rounded-lg"
            :aria-expanded="expanded"
        >
            <x-icon name="heroicon-o-wrench-screwdriver" class="w-3.5 h-3.5 text-accent shrink-0" />
            <span class="font-medium text-ink truncate">{{ $tool }}</span>
            <span class="text-muted truncate max-w-[10rem]" title="{{ $argsSummary }}">{{ \Illuminate\Support\Str::limit($argsSummary, 60) }}</span>
            <span class="ml-auto flex items-center gap-1.5 shrink-0">
                @if ($durationMs !== null)
                    <span class="tabular-nums text-muted">{{ number_format($durationMs / 1000, 1) }}s</span>
                @endif
                <x-ui.badge :variant="$statusBadge['variant']" class="text-[9px]">
                    {{ $statusBadge['label'] }}
                </x-ui.badge>
                <x-icon
                    name="heroicon-o-chevron-right"
                    class="w-3 h-3 text-muted transition-transform"
                    ::class="expanded ? 'rotate-90' : ''"
                />
            </span>
        </button>

        {{-- Detail panel: collapsed by default --}}
        <div x-show="expanded" x-cloak x-collapse class="border-t border-border-default px-2.5 py-2 text-xs">
            @if ($errorPayload !== null)
                <div class="space-y-1 text-red-500">
                    @if (isset($errorPayload['code']))
                        <div><span class="font-medium">{{ __('Code') }}:</span> {{ $errorPayload['code'] }}</div>
                    @endif
                    @if (isset($errorPayload['message']))
                        <div>{{ $errorPayload['message'] }}</div>
                    @endif
                    @if (isset($errorPayload['hint']))
                        <div class="text-muted">{{ __('Hint') }}: {{ $errorPayload['hint'] }}</div>
                    @endif
                </div>
            @elseif ($resultPreview !== '')
                <div class="text-muted whitespace-pre-wrap break-words">{{ $resultPreview }}</div>
                @if ($resultLength > 200)
                    <div class="mt-1 text-muted/70 tabular-nums">{{ number_format($resultLength) }} {{ __('chars total') }}</div>
                @endif
            @else
                <div class="text-muted">{{ __('No result preview available.') }}</div>
            @endif
        </div>
    </div>
</x-ai.activity.entry>
