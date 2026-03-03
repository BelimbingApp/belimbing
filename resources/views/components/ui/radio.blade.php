@props([
    'label' => null,
    'error' => null,
])

<div class="flex items-center gap-2">
    <input
        type="radio"
        {{ $attributes->class([
            'w-4 h-4 rounded-full border transition-colors',
            'border-border-input',
            'bg-surface-card',
            'accent-accent focus:ring-2 focus:ring-accent focus:ring-offset-2',
            'disabled:opacity-50 disabled:cursor-not-allowed',
            'border-status-danger' => $error,
        ]) }}
    >

    @if($label)
        <label {{ $attributes->only('for')->class(['text-sm font-medium text-ink']) }}>
            {{ $label }}
        </label>
    @endif

    @if($error)
        <p class="text-sm text-status-danger">{{ $error }}</p>
    @endif
</div>
