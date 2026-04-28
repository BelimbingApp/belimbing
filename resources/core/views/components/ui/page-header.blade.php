<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

?>

@props(['title', 'subtitle' => null, 'actions' => null, 'help' => null, 'pinnable' => null])

@php
    $resolvedPinnable = app(\App\Base\Menu\Services\PagePinResolver::class)->resolve((string) $title, $pinnable);
    $hasInteractive = $help || $resolvedPinnable;
    $alpineData = [];
    if ($help) $alpineData[] = 'helpOpen: false';
    if ($resolvedPinnable) {
        $alpineData[] = 'pinData: ' . json_encode($resolvedPinnable, JSON_UNESCAPED_SLASHES);
        $normalizer = app(\App\Base\Menu\Services\PinMetadataNormalizer::class);
        $normalizedPageUrl = $normalizer->normalizeUrl($resolvedPinnable['url']);
        $isCurrentlyPinned = auth()->check()
            && collect(auth()->user()->getPins())
                ->contains(fn (array $p) => $normalizer->normalizeUrl($p['url']) === $normalizedPageUrl);
        $alpineData[] = 'pagePinned: ' . ($isCurrentlyPinned ? 'true' : 'false');
    }
@endphp

<div @if($hasInteractive) x-data="{ {{ implode(', ', $alpineData) }} }" @endif
    @if($resolvedPinnable)
        @pins-synced.window="
            const norm = (u) => { try { return new URL(u, location.origin).pathname.replace(/\/+$/, '') || '/'; } catch { return u; } };
            const pageUrl = norm(pinData.url);
            pagePinned = $event.detail.pins.some(p => norm(p.url) === pageUrl);
        "
    @endif
>
    <div class="flex items-center justify-between gap-4">
        <div class="min-w-0 flex-1">
            <div class="inline-flex items-center gap-2 max-w-full mb-1">
                <h1 class="min-w-0 text-xl font-medium tracking-tight text-ink">{{ $title }}</h1>
                @if($resolvedPinnable)
                    <button
                        type="button"
                        @click="$dispatch('toggle-page-pin', pinData)"
                        class="shrink-0 inline-flex items-center justify-center w-6 h-6 rounded-sm transition-colors"
                        :class="pagePinned ? 'text-accent' : 'text-muted hover:text-accent'"
                        :title="pagePinned ? '{{ __('Unpin from sidebar') }}' : '{{ __('Pin to sidebar') }}'"
                        :aria-label="pagePinned ? '{{ __('Unpin :page from sidebar', ['page' => e($resolvedPinnable['label'])]) }}' : '{{ __('Pin :page to sidebar', ['page' => e($resolvedPinnable['label'])]) }}'"
                    >
                        <x-icon name="heroicon-o-pin" class="w-4 h-4" />
                    </button>
                @endif
                @if($help)
                    <x-ui.help size="lg" @click="helpOpen = !helpOpen" ::aria-expanded="helpOpen" />
                @endif
            </div>
            @if($subtitle)
                <p class="text-sm text-muted">{!! $subtitle !!}</p>
            @endif
        </div>
        @if($actions)
            <div class="shrink-0 flex items-center gap-2">
                {{ $actions }}
            </div>
        @endif
    </div>

    @if($help)
        <div
            x-cloak
            x-show="helpOpen"
            x-transition:enter="transition-all ease-out duration-200 motion-reduce:duration-0"
            x-transition:enter-start="max-h-0 opacity-0"
            x-transition:enter-end="max-h-96 opacity-100"
            x-transition:leave="transition-all ease-in duration-150 motion-reduce:duration-0"
            x-transition:leave-start="max-h-96 opacity-100"
            x-transition:leave-end="max-h-0 opacity-0"
            class="mt-3 overflow-hidden rounded-2xl border border-border-default bg-surface-card text-sm text-muted shadow-lg shadow-black/[0.02]"
            @click="helpOpen = false"
            role="note"
            aria-label="{{ __('Click to dismiss') }}"
        >
            <div class="p-4">
                {{ $help }}
            </div>
        </div>
    @endif
</div>
