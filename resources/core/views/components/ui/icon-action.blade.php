@props([
    'icon',
    'label',
    'title' => null,
    'href' => null,
    'type' => 'button',
])

@php
    $iconClasses = 'w-4 h-4';

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
