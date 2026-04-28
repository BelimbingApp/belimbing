@props([
    'label' => null,
    'value' => '',
    'field' => null,
    'saveMethod' => 'saveField',
    'saveValue' => 'val',
    'empty' => '-',
    'error' => null,
    'help' => null,
])

@php
    $value = $value ?? '';
    $inputId = $attributes->get('id') ?? 'edit-in-place-select-'.str()->uuid();
@endphp

<dl
    {{ $attributes->except('id') }}
    x-data="{ editing: false, helpOpen: false, val: @js((string) $value), original: @js((string) $value) }"
>
    @if ($label)
        <dt class="flex items-center gap-1 text-[11px] uppercase tracking-wider font-semibold text-muted">
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
            class="mt-0.5 overflow-hidden text-xs font-normal normal-case leading-5 tracking-normal text-muted"
        >
            <span class="block">{{ $help }}</span>
        </dd>
    @endif

    <dd class="mt-0.5">
        <button
            x-show="!editing"
            type="button"
            @click="editing = true; $nextTick(() => $refs.input.focus())"
            class="group flex cursor-pointer items-center gap-1.5 rounded px-1 py-0.5 -mx-1 text-left hover:bg-surface-subtle"
        >
            @isset($read)
                {{ $read }}
            @else
                <span class="text-sm text-ink" x-text="val || @js($empty)"></span>
            @endisset
            <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 transition-opacity group-hover:opacity-100" />
        </button>

        <select
            id="{{ $inputId }}"
            x-show="editing"
            x-ref="input"
            x-model="val"
            @change="editing = false; original = val; @if($field !== null) $wire.{{ $saveMethod }}(@js($field), {{ $saveValue }}) @else $wire.{{ $saveMethod }}({{ $saveValue }}) @endif"
            @keydown.escape="editing = false; val = original"
            @blur="editing = false"
            class="px-2 py-1 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
        >
            {{ $slot }}
        </select>

        @if ($error)
            <p class="mt-1 text-sm text-status-danger">{{ $error }}</p>
        @endif
    </dd>
</dl>
