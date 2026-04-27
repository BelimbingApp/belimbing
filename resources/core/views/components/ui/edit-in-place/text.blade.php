@props([
    'label' => null,
    'value' => '',
    'display' => null,
    'field',
    'saveMethod' => 'saveField',
    'empty' => '-',
    'error' => null,
    'type' => 'text',
    'inputmode' => null,
    'maxlength' => null,
    'monospace' => false,
    'tabular' => false,
])

@php
    $value = $value ?? '';
    $inputMode = $inputmode ?? null;
    $inputId = $attributes->get('id') ?? 'edit-in-place-text-'.str()->uuid();
@endphp

<dl
    {{ $attributes->except('id') }}
    x-data="{ editing: false, val: @js((string) $value), original: @js((string) $value) }"
>
    @if ($label)
        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">
            <label for="{{ $inputId }}">{{ $label }}</label>
        </dt>
    @endif

    <dd class="text-sm text-ink">
        <button
            x-show="!editing"
            type="button"
            @click="editing = true; $nextTick(() => $refs.input.select())"
            @class([
                'group flex cursor-pointer items-center gap-1.5 rounded px-1 py-0.5 -mx-1 text-left hover:bg-surface-subtle',
                'font-mono' => $monospace,
                'tabular-nums' => $tabular,
            ])
        >
            @if ($display !== null)
                <span>{{ $display }}</span>
            @else
                <span x-text="val || @js($empty)"></span>
            @endif
            <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 transition-opacity group-hover:opacity-100" />
        </button>

        <input
            id="{{ $inputId }}"
            x-show="editing"
            x-ref="input"
            x-model="val"
            @keydown.enter.prevent="editing = false; original = val; $wire.{{ $saveMethod }}(@js($field), val)"
            @keydown.escape="editing = false; val = original"
            @blur="if (editing) { editing = false; original = val; $wire.{{ $saveMethod }}(@js($field), val) }"
            type="{{ $type }}"
            @if ($inputMode) inputmode="{{ $inputMode }}" @endif
            @if ($maxlength) maxlength="{{ $maxlength }}" @endif
            @class([
                'w-full px-1 py-0.5 -mx-1 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent',
                'font-mono' => $monospace,
                'tabular-nums' => $tabular,
            ])
        />

        @if ($error)
            <p class="mt-1 text-sm text-status-danger">{{ $error }}</p>
        @endif
    </dd>
</dl>
