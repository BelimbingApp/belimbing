{{--
    Horizontal strip of key figures divided by hairlines: small uppercase label
    top-left, large tabular value right-aligned, optional small sub-line below.
    Compose with x-ui.stat children. Scrolls horizontally on narrow screens
    instead of squashing when min-width is given.
--}}
@props([
    'minWidth' => null, // CSS length, e.g. '44rem'
])

<div class="overflow-x-auto">
    <div
        {{ $attributes->class(['flex items-stretch text-sm tabular-nums']) }}
        @if($minWidth) style="min-width: {{ $minWidth }}" @endif
    >
        {{ $slot }}
    </div>
</div>
