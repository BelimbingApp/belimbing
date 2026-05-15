@props([
    'label' => null,
    'error' => null,
    'required' => false,
    'id' => 'secret-input-' . \Illuminate\Support\Str::random(8),
    'help' => null,
    'hasValue' => false,
    'savedLabel' => null,
    'savedValuePreview' => null,
    'emptySavedValueLabel' => null,
])

@php
    $inputAttributes = $attributes->except(['label', 'error', 'required', 'id', 'help', 'hasValue', 'savedLabel', 'savedValuePreview', 'emptySavedValueLabel']);
    $savedValuePreview = is_string($savedValuePreview) && $savedValuePreview !== '' ? $savedValuePreview : null;
@endphp

<div class="space-y-1" x-data="{ reveal: false }">
    @if($label)
        <label for="{{ $id }}" class="block text-[11px] uppercase tracking-wider font-semibold text-muted">
            {{ $label }}
            @if($required)
                <span class="text-status-danger">*</span>
            @endif
            @if($hasValue)
                <span class="ml-2 normal-case tracking-normal font-normal text-xs text-muted">
                    {{ $savedLabel ?? __('Saved secret set') }}
                    @if($savedValuePreview !== null)
                        <span class="font-mono">{{ $savedValuePreview }}</span>
                    @elseif($emptySavedValueLabel !== null)
                        <span>{{ $emptySavedValueLabel }}</span>
                    @endif
                </span>
            @endif
        </label>
    @endif

    <div class="relative">
        <input
            id="{{ $id }}"
            x-bind:type="reveal ? 'text' : 'password'"
            {{ $inputAttributes->class([
                'w-full px-input-x py-input-y pr-12 text-sm border rounded-2xl transition-colors',
                'border-border-input',
                'bg-surface-card',
                'text-ink',
                'placeholder:text-muted',
                'focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent',
                'disabled:opacity-50 disabled:cursor-not-allowed',
                'border-status-danger focus:ring-status-danger' => $error,
            ]) }}
        >

        <button
            type="button"
            class="absolute inset-y-0 right-0 inline-flex w-11 items-center justify-center text-muted transition-colors hover:text-ink focus:text-accent focus:outline-none"
            x-bind:aria-label="reveal ? @js(__('Hide secret')) : @js(__('Show secret'))"
            x-bind:aria-pressed="reveal.toString()"
            @click="reveal = ! reveal"
        >
            <x-icon name="heroicon-o-eye" class="h-4 w-4" />
        </button>
    </div>

    @if($error)
        <p class="text-sm text-status-danger">{{ $error }}</p>
    @endif

    @if($help)
        <x-ui.field-help :hint="$help" />
    @endif
</div>
