{{--
    Typed acknowledgment for destructive actions.

    The user must type the exact phrase before the caller reveals its danger
    buttons — the phrase states the consequence ("THIS CANNOT BE UNDONE",
    "uninstall commerce") instead of a magic word. The caller owns the armed
    state: bind a Livewire property (usually wire:model.live) and show the
    buttons only when the property matches the phrase AND a target is
    selected; the action method must re-check the phrase server-side.
--}}
@props([
    'phrase',
    'label' => null,
    'help' => null,
    'id' => 'acknowledge-'.\Illuminate\Support\Str::random(8),
])

@php
    $property = $attributes->wire('model')->value();
    $inputAttributes = $attributes->except(['phrase', 'label', 'help', 'id']);
@endphp

<div class="space-y-1">
    @if($label)
        <label for="{{ $id }}" class="block text-[11px] uppercase tracking-wider font-semibold text-muted">
            {{ $label }}
        </label>
    @endif

    @if($help)
        <p class="text-xs text-muted">{{ $help }}</p>
    @endif

    <input
        id="{{ $id }}"
        type="text"
        placeholder="{{ $phrase }}"
        autocomplete="off"
        autocapitalize="off"
        spellcheck="false"
        {{ $inputAttributes->class([
            'w-full px-input-x py-input-y text-sm border rounded-2xl transition-colors',
            'border-border-input bg-surface-card text-ink placeholder:text-muted/60',
            'focus:outline-none focus:ring-2 focus:ring-status-danger focus:border-transparent',
        ]) }}
    />

    @if($property)
        @error($property)
            <div class="mt-1 text-xs text-status-danger">{{ $message }}</div>
        @enderror
    @endif
</div>
