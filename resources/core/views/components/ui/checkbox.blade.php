@props([
    'label' => null,
    'error' => null,
    'id' => 'checkbox-' . \Illuminate\Support\Str::random(8),
    'help' => null,
])

<div class="space-y-1">
    <div class="flex items-center gap-2">
    <input
        id="{{ $id }}"
        type="checkbox"
        {{ $attributes->except(['id', 'help'])->class([
            'w-4 h-4 rounded border transition-colors',
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
    @endif

    @if($help)
        <div>
            <x-ui.field-help :hint="$help" />
        </div>
    @endif
</div>
