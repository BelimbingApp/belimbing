@props([
    'placeholder' => 'Search...',
    'id' => null,
    'name' => null,
])

<div class="relative">
    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-input-x text-muted">
        <x-icon
            name="heroicon-o-magnifying-glass"
            class="h-3.5 w-3.5"
        />
    </span>
    <input
        type="search"
        @if($id) id="{{ $id }}" @endif
        @if($name) name="{{ $name }}" @endif
        placeholder="{{ $placeholder }}"
        {{ $attributes->class([
            'w-full pl-10 pr-input-x py-input-y text-sm',
            'border border-border-input rounded-2xl',
            'bg-surface-card text-ink placeholder:text-muted',
            'focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent',
            '[&::-webkit-search-cancel-button]:appearance-none',
        ]) }}
    >
</div>
