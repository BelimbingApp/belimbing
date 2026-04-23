@props(['title' => null])

<div {{ $attributes->merge(['class' => 'bg-surface-card border border-border-default rounded-2xl shadow-sm']) }}>
    @if($title)
        <div class="px-6 py-4 border-b border-border-default">
            <h3 class="text-lg font-semibold text-ink">{{ $title }}</h3>
        </div>
    @endif

    <div class="p-card-inner">
        {{ $slot }}
    </div>
</div>
