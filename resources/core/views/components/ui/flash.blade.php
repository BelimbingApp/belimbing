<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
?>

@props([
    'variant' => 'success',
    'title' => null,
    'description' => null,
])

@php
    $config = match ($variant) {
        'danger', 'error' => [
            'bg' => 'bg-status-danger-subtle',
            'border' => 'border-status-danger-border',
            'text' => 'text-status-danger',
            'icon' => 'heroicon-o-exclamation-circle',
        ],
        'warning' => [
            'bg' => 'bg-status-warning-subtle',
            'border' => 'border-status-warning-border',
            'text' => 'text-status-warning',
            'icon' => 'heroicon-o-exclamation-triangle',
        ],
        'info' => [
            'bg' => 'bg-status-info-subtle',
            'border' => 'border-status-info-border',
            'text' => 'text-status-info',
            'icon' => 'heroicon-o-information-circle',
        ],
        default => [
            'bg' => 'bg-status-success-subtle',
            'border' => 'border-status-success-border',
            'text' => 'text-status-success',
            'icon' => 'heroicon-o-check-circle',
        ],
    };
@endphp

<div {{ $attributes->class(["pointer-events-auto w-full rounded-2xl border px-4 py-3 shadow-lg shadow-black/5 {$config['bg']} {$config['border']} {$config['text']}"]) }}>
    <div class="flex items-start gap-3">
        <x-icon :name="$config['icon']" class="mt-0.5 h-5 w-5 shrink-0" />
        <div class="min-w-0 flex-1 space-y-1">
            @if ($title)
                <p class="text-sm font-medium">{{ $title }}</p>
            @endif
            @if ($description)
                <p class="text-xs leading-5 opacity-90">{{ $description }}</p>
            @endif
            @if (trim((string) $slot) !== '')
                <div class="text-xs leading-5 opacity-90">{{ $slot }}</div>
            @endif
        </div>
    </div>
</div>

