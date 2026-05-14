@props([
    'label' => null,
    'error' => null,
    'required' => false,
    'type' => 'text',
    'id' => 'input-' . \Illuminate\Support\Str::random(8),
    'help' => null,
    'uppercase' => false,
    'prefix' => null,
    'suffix' => null,
])

@php
    $resolvedUppercase = $uppercase;
    $inputAttributes = $attributes->except(['label', 'error', 'required', 'id', 'help', 'uppercase', 'prefix', 'suffix']);

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

    <div class="relative">
        @if($prefix)
            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-input-x text-sm text-muted">
                {{ $prefix }}
            </span>
        @endif

        <input
            id="{{ $id }}"
            type="{{ $type }}"
            {{ $inputAttributes->class([
                'w-full px-input-x py-input-y text-sm border rounded-2xl transition-colors',
                'pl-10' => $prefix,
                'pr-14' => $suffix,
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

        @if($suffix)
            <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-input-x text-sm text-muted">
                {{ $suffix }}
            </span>
        @endif
    </div>

    @if($error)
        <p class="text-sm text-status-danger">{{ $error }}</p>
    @elseif($help)
        <x-ui.field-help :hint="$help" />
    @endif
</div>
