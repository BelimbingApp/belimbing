@props([
    'label' => null,
    'error' => null,
    'required' => false,
    'id' => 'select-' . \Illuminate\Support\Str::random(8),
    'name' => null,
    'help' => null,
    'block' => true, // full-width form control (default) vs inline (compact, e.g. per-page selector)
])

@php
    $widthClass = $block ? 'w-full' : 'w-auto';
@endphp

@if ($block)
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
            <select
                id="{{ $id }}"
                @if($name) name="{{ $name }}" @endif
                {{ $attributes->except(['label', 'error', 'required', 'id', 'name', 'help', 'block'])->class([
                    $widthClass,
                    'pl-input-x pr-10 py-input-y text-sm appearance-none',
                    'border border-border-input rounded-2xl',
                    'bg-surface-card text-ink',
                    'focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent',
                    'border-status-danger focus:ring-status-danger' => $error,
                ]) }}
            >
                {{ $slot }}
            </select>
            <x-icon
                name="heroicon-m-chevron-down"
                class="absolute right-[8px] top-1/2 -translate-y-1/2 h-4 w-4 text-muted pointer-events-none"
                aria-hidden="true"
            />
        </div>

        @if($error)
            <p class="text-sm text-status-danger">{{ $error }}</p>
        @endif

        @if($help)
            <x-ui.field-help :hint="$help" />
        @endif
    </div>
@else
    <div class="relative inline-flex items-center">
        <select
            id="{{ $id }}"
            @if($name) name="{{ $name }}" @endif
            {{ $attributes->except(['label', 'error', 'required', 'id', 'name', 'help', 'block'])->class([
                $widthClass,
                'pl-input-x pr-8 py-input-y text-sm appearance-none',
                'border border-border-input rounded-2xl',
                'bg-surface-card text-ink',
                'focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent',
            ]) }}
        >
            {{ $slot }}
        </select>
        <x-icon
            name="heroicon-m-chevron-down"
            class="absolute right-[6px] pointer-events-none h-3.5 w-3.5 text-muted"
            aria-hidden="true"
        />
    </div>
@endif
