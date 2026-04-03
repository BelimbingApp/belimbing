<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
?>

@props([
    'timestamp',
    'active' => false,
])

<x-ai.activity.entry type="thinking" :timestamp="$timestamp">
    <div class="flex items-center gap-1.5 text-xs text-muted">
        @if ($active)
            <span class="w-2 h-2 bg-accent rounded-full animate-pulse"></span>
        @endif
        <span>{{ __('Thinking…') }}</span>
        <span class="tabular-nums"><x-ui.datetime :value="$timestamp" format="time" /></span>
    </div>
</x-ai.activity.entry>
