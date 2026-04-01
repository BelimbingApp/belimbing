<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
?>

@props([
    'icon',
    'label',
    'title' => null,
    'href' => null,
    'type' => 'button',
    'size' => 'md',
])

@php
    $iconClasses = match($size) {
        'sm' => 'w-3.5 h-3.5',
        'md' => 'w-4 h-4',
        'lg' => 'w-5 h-5',
        default => 'w-4 h-4',
    };

    $displayTitle = $title ?? $label;
@endphp

@if($href !== null)
    <a
        href="{{ $href }}"
        title="{{ $displayTitle }}"
        {{ $attributes->class([
            'inline-flex items-center justify-center rounded p-1 text-accent transition-colors hover:bg-surface-subtle focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2',
        ]) }}
    >
        <x-icon :name="$icon" class="{{ $iconClasses }}" />
        <span class="sr-only">{{ $label }}</span>
    </a>
@else
    <button
        type="{{ $type }}"
        title="{{ $displayTitle }}"
        {{ $attributes->class([
            'inline-flex items-center justify-center rounded p-1 text-accent transition-colors hover:bg-surface-subtle focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
        ]) }}
    >
        <x-icon :name="$icon" class="{{ $iconClasses }}" />
        <span class="sr-only">{{ $label }}</span>
    </button>
@endif
