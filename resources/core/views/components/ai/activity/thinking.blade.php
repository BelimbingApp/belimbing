<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
?>

@props([
    'timestamp',
    'active' => false,
    'content' => '',
])

<x-ai.activity.entry type="thinking" :timestamp="$timestamp">
    <div class="flex items-center gap-1.5 text-xs text-muted">
        @if ($active)
            <span class="w-2 h-2 bg-accent rounded-full animate-pulse"></span>
        @endif
        <span>{{ $content !== '' ? __('Reasoning…') : __('Working…') }}</span>
        <span class="tabular-nums"><x-ui.datetime :value="$timestamp" format="time" /></span>
    </div>
    @if ($content !== '')
        <div class="text-xs text-muted/80 whitespace-pre-wrap break-words max-h-64 overflow-y-auto border-l-2 border-accent/20 pl-2 mt-1">{{ $content }}</div>
    @endif
</x-ai.activity.entry>
