@props([
    'label' => null,
    'value' => '',
    'field',
    'saveMethod' => 'saveField',
    'empty' => '-',
    'error' => null,
    'rows' => 4,
])

@php
    $value = $value ?? '';
@endphp

<div
    {{ $attributes }}
    x-data="{ editing: false, val: @js((string) $value), original: @js((string) $value) }"
>
    @if ($label)
        <dt class="mb-1 text-[11px] uppercase tracking-wider font-semibold text-muted">{{ $label }}</dt>
    @endif

    <dd class="text-sm text-ink">
        <button
            x-show="!editing"
            type="button"
            @click="editing = true; $nextTick(() => $refs.input.focus())"
            class="group flex min-h-8 cursor-pointer items-start gap-1.5 rounded px-1 py-0.5 -mx-1 text-left hover:bg-surface-subtle"
        >
            <span class="whitespace-pre-wrap" x-text="val || @js($empty)"></span>
            <x-icon name="heroicon-o-pencil" class="mt-0.5 w-3.5 h-3.5 text-muted opacity-0 transition-opacity group-hover:opacity-100" />
        </button>

        <textarea
            x-show="editing"
            x-ref="input"
            x-model="val"
            @keydown.escape="editing = false; val = original"
            @blur="if (editing) { editing = false; original = val; $wire.{{ $saveMethod }}(@js($field), val) }"
            rows="{{ $rows }}"
            @if ($label) aria-label="{{ $label }}" @endif
            class="w-full px-1 py-0.5 -mx-1 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
        ></textarea>

        @if ($error)
            <p class="mt-1 text-sm text-status-danger">{{ $error }}</p>
        @endif
    </dd>
</div>
