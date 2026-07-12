@props([
    'title' => null,
    'rounded' => true,
])

<div {{ $attributes->class([
    'border border-border-default bg-surface-card shadow-sm',
    'rounded-2xl' => $rounded,
]) }}>
    @if($title)
        <div class="px-6 py-4 border-b border-border-default">
            <h3 class="text-lg font-semibold text-ink">{{ $title }}</h3>
        </div>
    @endif

    <div class="p-card-inner">
        {{ $slot }}
    </div>
</div>
