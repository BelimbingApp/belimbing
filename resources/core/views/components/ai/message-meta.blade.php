<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
?>

@props([
    'timestamp',
    'provider' => null,
    'model' => null,
    'runId' => null,
    'tone' => 'muted',
])

@php
    $providerLabel = is_string($provider) && $provider !== '' && $provider !== 'unknown'
        ? $provider
        : null;
    $modelLabel = is_string($model) && $model !== '' && $model !== 'unknown'
        ? $model
        : null;

    $llmLabel = match (true) {
        $providerLabel !== null && $modelLabel !== null => $providerLabel.'/'.$modelLabel,
        $providerLabel !== null => $providerLabel,
        $modelLabel !== null => $modelLabel,
        default => null,
    };
    $runIdLabel = is_string($runId) && $runId !== '' ? $runId : null;

    $toneClasses = match ($tone) {
        'inverse' => 'text-accent-on/70 focus-visible:ring-accent-on/40',
        default => 'text-muted focus-visible:ring-accent/40',
    };
@endphp

<div x-data="{ tooltipOpen: false }" class="relative mt-1 inline-flex max-w-full text-[10px]">
    <span
        class="{{ $toneClasses }} inline-flex max-w-full items-center gap-1 rounded-md tabular-nums outline-none transition-colors focus-visible:ring-2 focus-visible:ring-offset-0"
    >
        <button
            type="button"
            tabindex="0"
            @mouseenter="tooltipOpen = true"
            @mouseleave="tooltipOpen = false"
            @focus="tooltipOpen = true"
            @blur="tooltipOpen = false"
            class="shrink-0 rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-accent/40 focus-visible:ring-offset-0"
        >
            <x-ui.datetime :value="$timestamp" format="time" />
        </button>

        @if ($llmLabel !== null)
            <span aria-hidden="true" class="shrink-0">·</span>
            <span class="truncate">
                {{ $llmLabel }}
            </span>
        @endif

        @if ($runIdLabel !== null)
            <span aria-hidden="true" class="shrink-0">·</span>
            <span class="relative inline-flex" x-data="{ runIdTooltipOpen: false }">
                <button
                    type="button"
                    @mouseenter="runIdTooltipOpen = true"
                    @mouseleave="runIdTooltipOpen = false"
                    @focus="runIdTooltipOpen = true"
                    @blur="runIdTooltipOpen = false"
                    tabindex="0"
                    class="truncate outline-none focus-visible:ring-2 focus-visible:ring-accent/40 focus-visible:ring-offset-0"
                >
                    {{ $runIdLabel }}
                </button>
                <span
                    x-show="runIdTooltipOpen"
                    x-cloak
                    x-transition.opacity.duration.100ms
                    role="tooltip"
                    class="pointer-events-none absolute bottom-full left-0 z-20 mb-1 whitespace-nowrap rounded-lg border border-border-default bg-surface-card px-2 py-1 text-[10px] text-ink shadow-sm"
                >
                    {{ __('Run ID') }}
                </span>
            </span>
        @endif
    </span>

    <span
        x-show="tooltipOpen"
        x-cloak
        x-transition.opacity.duration.100ms
        role="tooltip"
        class="pointer-events-none absolute bottom-full left-0 z-20 mb-1 whitespace-nowrap rounded-lg border border-border-default bg-surface-card px-2 py-1 text-[10px] text-ink shadow-sm"
    >
        <x-ui.datetime :value="$timestamp" format="datetime" />
    </span>
</div>
