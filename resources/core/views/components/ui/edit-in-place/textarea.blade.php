@props([
    'label' => null,
    'value' => '',
    'field',
    'saveMethod' => 'saveField',
    'empty' => '-',
    'error' => null,
    'rows' => 4,
    'help' => null,
])

@php
    $value = $value ?? '';
    $inputId = $attributes->get('id') ?? 'edit-in-place-textarea-'.str()->uuid();
@endphp

<dl
    {{ $attributes->except('id') }}
    x-data="{ editing: false, helpOpen: false, val: @js((string) $value), original: @js((string) $value) }"
>
    @if ($label)
        <dt class="mb-1 flex items-center gap-1 text-[11px] uppercase tracking-wider font-semibold text-muted">
            <label for="{{ $inputId }}">{{ $label }}</label>
            @if ($help)
                <button
                    type="button"
                    class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full transition-colors focus:outline-none"
                    :class="helpOpen ? 'text-accent' : 'text-muted/70 hover:text-muted'"
                    aria-label="{{ __('Field help') }}"
                    aria-expanded="false"
                    x-bind:aria-expanded="helpOpen.toString()"
                    @click="helpOpen = ! helpOpen"
                >
                    <x-icon name="heroicon-o-question-mark-circle" class="h-3.5 w-3.5" />
                </button>
            @endif
        </dt>
    @endif

    @if ($help)
        <dd
            x-cloak
            x-show="helpOpen"
            x-transition:enter="transition-all ease-out duration-200"
            x-transition:enter-start="max-h-0 opacity-0"
            x-transition:enter-end="max-h-16 opacity-100"
            x-transition:leave="transition-all ease-in duration-150"
            x-transition:leave-start="max-h-16 opacity-100"
            x-transition:leave-end="max-h-0 opacity-0"
            class="-mt-0.5 mb-1 overflow-hidden text-xs font-normal normal-case leading-5 tracking-normal text-muted"
        >
            <span class="block">{{ $help }}</span>
        </dd>
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
            id="{{ $inputId }}"
            x-show="editing"
            x-ref="input"
            x-model="val"
            @keydown.escape="editing = false; val = original"
            @blur="if (editing) { editing = false; if (val !== original) { original = val; $wire.{{ $saveMethod }}(@js($field), val) } }"
            rows="{{ $rows }}"
            class="w-full px-1 py-0.5 -mx-1 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
        ></textarea>

        @if ($error)
            <p class="mt-1 text-sm text-status-danger">{{ $error }}</p>
        @endif
    </dd>
</dl>
