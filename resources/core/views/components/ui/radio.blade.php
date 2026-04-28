@props([
    'label' => null,
    'error' => null,
    'id' => 'radio-' . \Illuminate\Support\Str::random(8),
    'help' => null,
])

<div>
    <div class="flex items-center gap-2">
    <input
        id="{{ $id }}"
        type="radio"
        {{ $attributes->except(['id', 'help'])->class([
            'w-4 h-4 rounded-full border transition-colors',
            'border-border-input',
            'bg-surface-card',
            'accent-accent focus:ring-2 focus:ring-accent focus:ring-offset-2',
            'disabled:opacity-50 disabled:cursor-not-allowed',
            'border-status-danger' => $error,
        ]) }}
    >

    @if($label)
        <label for="{{ $id }}" class="text-sm font-medium text-ink">
            {{ $label }}
        </label>
    @endif
    </div>

    @if($error)
        <p class="text-sm text-status-danger">{{ $error }}</p>
    @elseif($help)
        <div class="pl-6 leading-none">
            <x-ui.field-help :hint="$help" />
        </div>
    @endif
</div>
