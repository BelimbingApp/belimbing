@props([
    'label' => null,
    'error' => null,
    'required' => false,
    'type' => 'text',
    'id' => 'input-' . \Illuminate\Support\Str::random(8),
    'help' => null,
    'uppercase' => false,
])

@php
    $resolvedUppercase = $uppercase;
    $inputAttributes = $attributes->except(['label', 'error', 'required', 'id', 'help', 'uppercase']);

    if ($uppercase && ! $inputAttributes->has('autocapitalize')) {
        $inputAttributes = $inputAttributes->merge(['autocapitalize' => 'characters']);
    }
@endphp

<div class="space-y-1">
    @if($label)
        <label for="{{ $id }}" class="block text-[11px] uppercase tracking-wider font-semibold text-muted">
            {{ $label }}
            @if($required)
                <span class="text-status-danger">*</span>
            @endif
        </label>
    @endif

    <input
        id="{{ $id }}"
        type="{{ $type }}"
        {{ $inputAttributes->class([
            'w-full px-input-x py-input-y text-sm border rounded-2xl transition-colors',
            'border-border-input',
            'bg-surface-card',
            'text-ink',
            'uppercase' => $resolvedUppercase,
            'placeholder:text-muted',
            'focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent',
            'disabled:opacity-50 disabled:cursor-not-allowed',
            'border-status-danger focus:ring-status-danger' => $error,
        ]) }}
    >

    @if($error)
        <p class="text-sm text-status-danger">{{ $error }}</p>
    @elseif($help)
        <x-ui.field-help :hint="$help" />
    @endif
</div>
