@props([
    'field'     => null,   // Alpine data field; auto-derives stepTime/parseTime/:value
    'withSteps' => true,   // Show − / + step buttons
])
@php
    $dec      = $field ? "stepTime('{$field}', -1)" : null;
    $inc      = $field ? "stepTime('{$field}', 1)"  : null;
    $val      = $field ? "{$field}Hhmm"             : null;
    $chg      = $field ? "parseTime('{$field}', \$event.target.value)" : null;
    $hasSteps = $withSteps && $dec;
@endphp
<div class="inline-flex items-center border border-border-input rounded-full h-8 overflow-hidden focus-within:border-accent focus-within:ring-2 focus-within:ring-accent/20">
    @if ($hasSteps)
    <button type="button"
            class="w-7 h-8 text-muted hover:bg-surface-subtle hover:text-ink text-base leading-none transition-colors shrink-0"
            x-on:click="{{ $dec }}">−</button>
    @endif
    <input
        class="{{ $hasSteps ? 'w-16' : 'w-24 px-3' }} text-center text-sm font-semibold text-ink bg-transparent border-none outline-none tabular-nums"
        @if ($val) :value="{{ $val }}" @endif
        @if ($chg) x-on:change="{{ $chg }}" @endif
        x-on:keydown.enter="$event.target.blur()"
        {{ $attributes }}
    >
    @if ($hasSteps)
    <button type="button"
            class="w-7 h-8 text-muted hover:bg-surface-subtle hover:text-ink text-base leading-none transition-colors shrink-0"
            x-on:click="{{ $inc }}">+</button>
    @endif
</div>
