<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
?>

@props([
    'timestamp',
    'type',
    'tone' => 'default',
])

@php
    $icon = match ($type) {
        'thinking' => 'heroicon-o-light-bulb',
        'tool_call', 'tool_result' => 'heroicon-o-wrench-screwdriver',
        'error' => 'heroicon-o-exclamation-triangle',
        default => null,
    };

    $iconColor = match ($type) {
        'thinking' => 'text-muted',
        'tool_call', 'tool_result' => 'text-accent',
        'error' => 'text-red-500',
        default => 'text-muted',
    };
@endphp

<div class="flex gap-2 py-1">
    @if ($icon)
        <div class="shrink-0 mt-0.5">
            <x-icon :name="$icon" class="w-4 h-4 {{ $iconColor }}" />
        </div>
    @endif
    <div class="min-w-0 flex-1">
        {{ $slot }}
    </div>
</div>
