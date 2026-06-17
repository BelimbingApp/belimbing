@props([
    'field'     => null,   // Alpine data field; auto-derives stepTime/parseTime/:value
    'withSteps' => false,  // Show − / + step buttons; false renders a native time picker
])
@php
    $dec = $field ? "stepTime('{$field}', -1)" : null;
    $inc = $field ? "stepTime('{$field}', 1)"  : null;
    $val = $field ? "{$field}Hhmm"             : null;
    $chg = $field ? "parseTime('{$field}', \$event.target.value)" : null;
@endphp

@if ($withSteps && $dec)
<div class="inline-flex items-center border border-border-input rounded-full h-[calc(1.25rem+(var(--spacing-input-y)*2)+2px)] overflow-hidden focus-within:border-accent focus-within:ring-2 focus-within:ring-accent/20">
    <button type="button"
            class="w-7 h-full text-muted hover:bg-surface-subtle hover:text-ink text-base leading-none transition-colors shrink-0"
            x-on:click="{{ $dec }}">−</button>
    <input
        {{ $attributes->merge(['aria-label' => __('Time')])->class([
            'w-16 h-full text-center text-sm font-normal leading-5 text-ink bg-transparent border-none outline-none tabular-nums',
        ]) }}
        @if ($val) :value="{{ $val }}" @endif
        @if ($chg) x-on:change="{{ $chg }}" @endif
        x-on:keydown.enter="$event.target.blur()"
    >
    <button type="button"
            class="w-7 h-full text-muted hover:bg-surface-subtle hover:text-ink text-base leading-none transition-colors shrink-0"
            x-on:click="{{ $inc }}">+</button>
</div>
@else
<input
    type="time"
    {{ $attributes->merge(['aria-label' => __('Time')])->class([
        'w-[7.5rem] h-[calc(1.25rem+(var(--spacing-input-y)*2)+2px)] pl-3 pr-2 py-input-y text-sm font-normal leading-5 tabular-nums border border-border-input rounded-2xl',
        'bg-surface-card text-ink transition-colors',
        'focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent',
        'disabled:opacity-50 disabled:cursor-not-allowed',
    ]) }}
    @if ($val) :value="{{ $val }}" @endif
    @if ($chg) x-on:change="{{ $chg }}" @endif
    x-on:keydown.enter="$event.target.blur()"
>
@endif
