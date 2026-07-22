@props([
    'label' => null,
    'error' => null,
    'required' => false,
    'id' => 'secret-input-' . \Illuminate\Support\Str::random(8),
    'help' => null,
    'hasValue' => false,
    'showRevealButton' => false,
    'savedMask' => '******',
])

@php
    $componentProps = ['label', 'error', 'required', 'id', 'help', 'hasValue', 'showRevealButton', 'savedMask', 'savedPlaceholder'];
    $inputAttributes = $attributes->except($componentProps);
    $savedMask = is_string($savedMask) && $savedMask !== '' ? $savedMask : '******';
    $maskCharacter = mb_substr($savedMask, 0, 1) ?: '*';
    $usesSentinelMask = $hasValue && ! $showRevealButton;

    $wireModel = null;
    $wireMode = 'deferred';
    foreach (['wire:model.live', 'wire:model.blur', 'wire:model.defer', 'wire:model'] as $wireKey) {
        if ($inputAttributes->has($wireKey)) {
            $wireModel = (string) $inputAttributes->get($wireKey);
            $wireMode = match ($wireKey) {
                'wire:model.live' => 'live',
                'wire:model.blur' => 'blur',
                default => 'deferred',
            };
            $inputAttributes = $inputAttributes->except($wireKey);
            break;
        }
    }

    $initialRaw = trim((string) ($inputAttributes->get('value') ?? ''));
    $isUniformMask = $initialRaw !== ''
        && $initialRaw === str_repeat($maskCharacter, mb_strlen($initialRaw));

    if ($usesSentinelMask && ($initialRaw === '' || $initialRaw === $savedMask)) {
        $displayValue = $savedMask;
        $wireValue = $savedMask;
        $initialSecret = null;
    } elseif ($initialRaw !== '' && $initialRaw !== $savedMask && ! $isUniformMask) {
        $initialSecret = $initialRaw;
        $displayValue = str_repeat($maskCharacter, mb_strlen($initialRaw));
        $wireValue = $initialRaw;
    } else {
        $initialSecret = null;
        $displayValue = $initialRaw;
        $wireValue = $initialRaw;
    }

    $inputAttributes = $inputAttributes->except('value')->merge(['value' => $displayValue]);
    $managesWire = $wireModel !== null;
@endphp

<div
    class="space-y-1"
    x-data="{
        reveal: false,
        savedMask: @js($savedMask),
        maskCharacter: @js($maskCharacter),
        usesSentinel: @js($usesSentinelMask),
        showRevealButton: @js($showRevealButton),
        wireModel: @js($wireModel),
        wireMode: @js($wireMode),
        focusSnapshot: '',
        pendingSecret: @js($initialSecret),
        didEdit: false,
        hasInputValue: @js($displayValue !== ''),
        isUniformMask(value) {
            return value !== '' && [...value].every((character) => character === this.maskCharacter);
        },
        hasRealSecret() {
            return this.pendingSecret !== null && this.pendingSecret !== '';
        },
        showRevealAffordance() {
            return this.showRevealButton;
        },
        syncInputValue() {
            this.hasInputValue = $refs.input.value.length > 0;
            if (! this.hasInputValue) {
                this.reveal = false;
            }
        },
        setDisplay(value) {
            $refs.input.value = value;
            this.syncInputValue();
        },
        clearSecret() {
            this.pendingSecret = null;
            this.didEdit = false;
            this.reveal = false;
            this.setDisplay('');
            this.syncWire('');
        },
        syncWire(value, live = this.wireMode === 'live') {
            if (this.wireModel !== null) {
                $wire.set(this.wireModel, value, live);
            }
        },
        lengthMask(secret) {
            return this.maskCharacter.repeat(secret.length);
        },
        restingState() {
            if (this.usesSentinel && ! this.hasRealSecret()) {
                return { display: this.savedMask, wire: this.savedMask };
            }

            if (! this.hasRealSecret()) {
                return { display: '', wire: '' };
            }

            return {
                display: this.lengthMask(this.pendingSecret),
                wire: this.pendingSecret,
            };
        },
        selectAllForEdit() {
            this.$nextTick(() => {
                $refs.input.select();
                this.focusSnapshot = $refs.input.value;
                this.didEdit = false;
            });
        },
        handleFocus() {
            if (this.reveal) {
                return;
            }

            this.selectAllForEdit();
        },
        handleInput() {
            const value = $refs.input.value;
            this.didEdit = true;

            if (this.isUniformMask(value)) {
                this.syncInputValue();

                return;
            }

            this.pendingSecret = value;
            this.syncWire(value);
            this.syncInputValue();
        },
        handleBlur() {
            if (this.reveal) {
                this.reveal = false;

                if (this.hasRealSecret()) {
                    this.setDisplay(this.lengthMask(this.pendingSecret));
                }
            }

            const value = $refs.input.value;

            if (! this.didEdit || value === this.focusSnapshot) {
                const resting = this.restingState();
                this.setDisplay(resting.display);
                this.syncWire(resting.wire, this.wireMode === 'blur');

                return;
            }

            if (value === '') {
                this.pendingSecret = null;
                this.setDisplay('');
                this.syncWire('', this.wireMode === 'blur');

                return;
            }

            const secret = this.isUniformMask(value) ? (this.pendingSecret ?? '') : value;

            if (secret === '') {
                this.pendingSecret = null;
                this.setDisplay('');
                this.syncWire('', this.wireMode === 'blur');

                return;
            }

            this.pendingSecret = secret;
            this.syncWire(secret, this.wireMode === 'blur');
            this.setDisplay(this.lengthMask(secret));
        },
        toggleReveal() {
            if (! this.hasRealSecret()) {
                return;
            }

            this.reveal = ! this.reveal;
            $refs.input.value = this.reveal
                ? this.pendingSecret
                : this.lengthMask(this.pendingSecret);
            this.syncInputValue();
        },
    }"
    x-init="$nextTick(() => {
        if (@js($managesWire)) {
            syncWire(@js($wireValue));
        }

        syncInputValue();
    })"
    x-on:clear-secret-input.window="if ($event.detail.id === @js($id)) clearSecret()"
>
    @if($label)
        <label for="{{ $id }}" class="block text-[11px] uppercase tracking-wider font-semibold text-muted">
            {{ $label }}
            @if($required)
                <span class="text-status-danger">*</span>
            @endif
        </label>
    @endif

    <div class="relative">
        <input
            id="{{ $id }}"
            x-ref="input"
            @if($managesWire) wire:ignore @endif
            type="password"
            x-bind:type="reveal ? 'text' : 'password'"
            x-on:focus="handleFocus()"
            x-on:blur="handleBlur()"
            x-on:input="handleInput()"
            {{ $inputAttributes->class([
                'w-full px-input-x py-input-y text-sm border rounded-2xl transition-colors',
                'border-border-input',
                'bg-surface-card',
                'text-ink',
                'placeholder:text-muted',
                'focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent',
                'disabled:opacity-50 disabled:cursor-not-allowed',
                'border-status-danger focus:ring-status-danger' => $error,
            ]) }}
            x-bind:class="{ 'pr-12': showRevealAffordance() }"
        >

        @if($showRevealButton)
            <button
                type="button"
                class="absolute inset-y-0 right-0 inline-flex w-11 items-center justify-center rounded-r-2xl transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 disabled:cursor-default disabled:opacity-40"
                x-show="showRevealAffordance()"
                x-cloak
                x-bind:disabled="! hasRealSecret()"
                x-bind:class="reveal ? 'text-accent' : 'text-muted'"
                x-bind:aria-label="reveal ? @js(__('Hide secret')) : @js(__('Show secret'))"
                x-bind:aria-pressed="reveal.toString()"
                x-on:mousedown.prevent
                x-on:click="toggleReveal()"
            >
                <x-icon name="heroicon-o-eye" class="h-4 w-4" />
            </button>
        @endif
    </div>

    @if($error)
        <p class="text-sm text-status-danger">{{ $error }}</p>
    @endif

    @if($help)
        <x-ui.field-help :hint="$help" />
    @endif
</div>
