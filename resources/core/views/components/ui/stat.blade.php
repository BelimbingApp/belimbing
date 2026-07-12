{{--
    One figure inside an x-ui.stat-strip. Default slot is the value; the
    optional `sub` slot renders the small context line under it.
--}}
@props([
    'label',
])

{{-- Uniform cell rhythm: every cell pads left off its divider and runs its
     right-aligned value to the cell edge, exactly like the last cell runs to
     the strip edge — the old px-8/last:pr-0 mix made middle and outer cells
     visibly different inside dashboard widgets. --}}
<div {{ $attributes->class(['min-w-0 flex-1 border-l border-border-default pl-8 pr-0 first:border-l-0 first:pl-0']) }}>
    <div class="text-[10px] font-medium uppercase tracking-wide text-muted">{{ $label }}</div>
    <div class="mt-1 whitespace-nowrap text-right font-mono text-base text-ink">{{ $slot }}</div>
    @isset($sub)
        <div class="mt-1 whitespace-nowrap text-right text-xs text-muted">{{ $sub }}</div>
    @endisset
</div>
