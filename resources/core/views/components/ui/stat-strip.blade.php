{{--
    Horizontal strip of key figures divided by hairlines: small uppercase label
    top-left, large tabular value right-aligned, optional small sub-line below.
    Compose with x-ui.stat children. The strip is at least container-wide and
    grows to intrinsic content width, so it scrolls only when the actual stat
    content needs more room.
--}}
@props([
    'minWidth' => null, // CSS length, e.g. '44rem'
])

<div class="overflow-x-auto">
    <div
        {{ $attributes->class(['inline-flex min-w-full w-max items-stretch text-sm tabular-nums']) }}
        @if($minWidth) style="min-width: max(100%, {{ $minWidth }})" @endif
    >
        {{ $slot }}
    </div>
</div>
