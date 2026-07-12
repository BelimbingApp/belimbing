{{--
    One figure inside an x-ui.stat-strip. Default slot is the value; the
    optional `sub` slot renders the small context line under it.
--}}
@props([
    'label',
])

{{-- Uniform cell rhythm: the last value's gap to the strip's true edge comes
     from the card's own p-card-inner padding (0.5rem) — every other cell
     needs that same trailing gap before its own divider, or values touch the
     next divider with no breathing room while the last one floats free.
     pr-card-inner (not pr-8) matches that reference gap exactly; last:pr-0
     avoids doubling it where the card's own padding already applies. --}}
<div {{ $attributes->class(['min-w-0 flex-1 border-l border-border-default pl-8 pr-card-inner last:pr-0 first:border-l-0 first:pl-0']) }}>
    <div class="text-[10px] font-medium uppercase tracking-wide text-muted">{{ $label }}</div>
    <div class="mt-1 whitespace-nowrap text-right font-mono text-base text-ink">{{ $slot }}</div>
    @isset($sub)
        <div class="mt-1 whitespace-nowrap text-right text-xs text-muted">{{ $sub }}</div>
    @endisset
</div>
