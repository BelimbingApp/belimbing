@props([
    'id',
    'label',
    'accept' => null,
    'multiple' => false,
    'errorName' => null,
    'help' => null,
])

@php
    $resolvedErrorName = $errorName ?? $attributes->get('wire:model') ?? $attributes->get('wire:model.live');
@endphp

<div class="space-y-1.5">
    <label for="{{ $id }}" class="block text-[11px] font-semibold uppercase tracking-wider text-muted">
        {{ $label }}
    </label>
    <input
        id="{{ $id }}"
        type="file"
        @if($accept) accept="{{ $accept }}" @endif
        @if($multiple) multiple @endif
        {{ $attributes->class([
            'block w-full rounded-lg border border-border-input bg-surface-card px-input-x py-input-y text-sm text-ink',
            'file:mr-3 file:rounded-md file:border-0 file:bg-surface-subtle file:px-input-x file:py-input-y file:text-sm file:font-medium file:text-ink',
            'hover:file:bg-surface-bar focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2',
        ]) }}
    />
    @if($help)
        <p class="text-xs text-muted">{{ $help }}</p>
    @endif
    @if($resolvedErrorName && isset($errors))
        @error($resolvedErrorName)
            <p class="text-sm text-status-danger">{{ $message }}</p>
        @enderror
    @endif
</div>
