@props([
    'field' => null,   // Alpine numeric field, bound via :value
    'id'    => null,
    'label' => null,
    'set'   => null,   // Alpine setter, called as set('field', value)
    'step'  => '1',    // − / + increment (Alpine expression, e.g. 'snap')
])
@php
    $wired = $field && $set;
    $dec = $wired ? "{$set}('{$field}', {$field} - {$step})" : null;
    $inc = $wired ? "{$set}('{$field}', {$field} + {$step})" : null;
    $chg = $wired ? "{$set}('{$field}', \$event.target.value)" : null;
    $labelText = $label ?? $attributes->get('aria-label') ?? __('Value');
    $inputId = $id
        ?? $attributes->get('id')
        ?? ($field ? 'integer-input-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $field) : 'integer-input');
@endphp

<div class="inline-flex items-center border border-border-input rounded-full h-[calc(1.25rem+(var(--spacing-input-y)*2)+2px)] overflow-hidden focus-within:border-accent focus-within:ring-2 focus-within:ring-accent/20">
    <button type="button"
            class="w-7 h-full text-muted hover:bg-surface-subtle hover:text-ink text-base leading-none transition-colors shrink-0"
            x-on:click="{{ $dec }}">−</button>
    <label class="sr-only" for="{{ $inputId }}">{{ $labelText }}</label>
    <input
        id="{{ $inputId }}"
        aria-label="{{ $labelText }}"
        type="number" inputmode="numeric"
        {{ $attributes->except(['id', 'aria-label'])->class([
            'w-12 h-full text-center text-sm font-normal leading-5 text-ink bg-transparent border-none outline-none tabular-nums',
            '[appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none',
        ]) }}
        @if ($field) :value="{{ $field }}" @endif
        @if ($chg) x-on:change="{{ $chg }}" @endif
        x-on:keydown.enter="$event.target.blur()"
    >
    <button type="button"
            class="w-7 h-full text-muted hover:bg-surface-subtle hover:text-ink text-base leading-none transition-colors shrink-0"
            x-on:click="{{ $inc }}">+</button>
</div>
