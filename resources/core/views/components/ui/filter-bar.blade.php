@props([
    'search',
])

<div {{ $attributes->class('flex flex-wrap gap-3') }}>
    <div class="min-w-[min(100%,20rem)] flex-[2_1_20rem]">
        {{ $search }}
    </div>

    <div class="contents [&>*]:min-w-[min(100%,12rem)] [&>*]:flex-[1_1_12rem]">
        {{ $slot }}
    </div>
</div>
