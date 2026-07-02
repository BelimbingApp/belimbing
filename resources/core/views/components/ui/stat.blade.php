{{--
    One figure inside an x-ui.stat-strip. Default slot is the value; the
    optional `sub` slot renders the small context line under it.
--}}
@props([
    'label',
])

<div {{ $attributes->class(['min-w-0 flex-1 border-l border-border-default px-8 first:border-l-0 first:pl-0 last:pr-0']) }}>
    <div class="text-[10px] font-medium uppercase tracking-wide text-muted">{{ $label }}</div>
    <div class="mt-1 whitespace-nowrap text-right font-mono text-base text-ink">{{ $slot }}</div>
    @isset($sub)
        <div class="mt-1 whitespace-nowrap text-right text-xs text-muted">{{ $sub }}</div>
    @endisset
</div>
