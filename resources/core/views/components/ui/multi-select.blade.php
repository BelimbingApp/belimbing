@props([
    'id',
    'label' => null,
    'options' => [],
    'selected' => [],
    'placeholder' => null,
    'selectionLabel' => ':count option selected|:count options selected',
    'accessibleLabel' => null,
])

@php
    $selectedCount = count($selected);
    $modelAttributes = $attributes->whereStartsWith('wire:model');
    $containerAttributes = $attributes->whereDoesntStartWith('wire:model');
@endphp

<div
    x-data="{ open: false }"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
    {{ $containerAttributes->class('relative space-y-1') }}
>
    @if ($label)
        <span id="{{ $id }}-label" class="block text-[11px] uppercase tracking-wider font-semibold text-muted">
            {{ $label }}
        </span>
    @endif

    <button
        id="{{ $id }}"
        type="button"
        @click="open = ! open"
        :aria-expanded="open"
        aria-controls="{{ $id }}-options"
        @if ($label) aria-labelledby="{{ $id }}-label {{ $id }}" @endif
        @if (! $label) aria-label="{{ $accessibleLabel ?? $placeholder ?? __('Select options') }}" @endif
        class="flex w-full items-center justify-between gap-3 rounded-2xl border border-border-input bg-surface-card px-input-x py-input-y text-left text-sm text-ink transition-colors hover:bg-surface-subtle focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent"
    >
        <span class="truncate">
            {{ $selectedCount > 0
                ? trans_choice($selectionLabel, $selectedCount, ['count' => $selectedCount])
                : ($placeholder ?? __('All options')) }}
        </span>
        <x-icon
            name="heroicon-m-chevron-down"
            class="h-4 w-4 shrink-0 text-muted transition-transform"
            x-bind:class="open ? 'rotate-180' : ''"
            aria-hidden="true"
        />
    </button>

    <div
        id="{{ $id }}-options"
        x-show="open"
        x-cloak
        x-transition.opacity.duration.100ms
        class="absolute left-0 z-30 mt-1 max-h-64 w-full min-w-56 overflow-y-auto rounded-2xl border border-border-default bg-surface-card p-2 shadow-lg"
    >
        @forelse ($options as $option)
            <label
                for="{{ $id }}-option-{{ $option['value'] }}"
                class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm font-medium text-ink hover:bg-surface-subtle"
            >
                <input
                    id="{{ $id }}-option-{{ $option['value'] }}"
                    type="checkbox"
                    value="{{ $option['value'] }}"
                    {{ $modelAttributes }}
                    class="h-4 w-4 shrink-0 rounded border border-border-input bg-surface-card accent-accent focus:ring-2 focus:ring-accent focus:ring-offset-2"
                >
                <span>{{ $option['label'] }}</span>
            </label>
        @empty
            <p class="px-2 py-1.5 text-sm text-muted">{{ __('No options available.') }}</p>
        @endforelse
    </div>
</div>
