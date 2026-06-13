@props([
    'label' => null,
    'value' => '',
    'display' => null,
    'empty' => '-',
    'error' => null,
    'help' => null,
    'placeholder' => '',
    'options' => [],
    'editable' => false,
    'searchMethod' => null,
    'searchUrl' => null,
    'disabled' => false,
])

@php
    $value = $value ?? '';
    $inputId = $attributes->get('id') ?? 'edit-in-place-combobox-'.str()->uuid();
    $option = collect($options)->firstWhere('value', (string) $value);
    $displayValue = $display ?? ($option['label'] ?? $value);
@endphp

<dl
    wire:key="eip-combobox-{{ $attributes->get('id') ?? 'field' }}"
    {{ $attributes->whereDoesntStartWith('wire:model')->except('id') }}
    x-data="{
        editing: false,
        helpOpen: false,
        val: @js((string) $value),
        original: @js((string) $value),
        display: @js((string) $displayValue),
        originalDisplay: @js((string) $displayValue),
        commit(detail) {
            this.val = String(detail.value ?? '')
            this.original = this.val
            this.display = String(detail.label ?? detail.value ?? '')
            this.originalDisplay = this.display
            this.editing = false
        },
        cancel() {
            this.val = this.original
            this.display = this.originalDisplay
            this.editing = false
        },
    }"
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
            <span class="block">{!! $help !!}</span>
        </dd>
    @endif

    <dd class="mt-0.5 text-sm text-ink">
        <button
            x-show="!editing"
            type="button"
            @click="editing = true; $nextTick(() => document.getElementById(@js($inputId))?.focus())"
            @disabled($disabled)
            @class([
                'group flex cursor-pointer items-center gap-1.5 rounded px-1 py-0.5 -mx-1 text-left hover:bg-surface-subtle',
                'disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-transparent' => $disabled,
            ])
        >
            <span x-text="display || @js($empty)"></span>
            <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 transition-opacity group-hover:opacity-100" />
        </button>

        <div
            x-cloak
            x-show="editing"
            x-transition.opacity.duration.150ms
            @combobox-committed.stop="commit($event.detail)"
            @combobox-cancelled.stop="cancel()"
            @keydown.escape="cancel()"
            @click.outside="editing = false"
        >
            <x-ui.combobox
                id="{{ $inputId }}"
                {{ $attributes->whereStartsWith('wire:model') }}
                :placeholder="$placeholder"
                :options="$options"
                :editable="$editable"
                :search-method="$searchMethod"
                :search-url="$searchUrl"
                :disabled="$disabled"
                :error="$error"
            />
        </div>

        @if ($error)
            <p x-show="!editing" class="mt-1 text-sm text-status-danger">{{ $error }}</p>
        @endif
    </dd>
</dl>
