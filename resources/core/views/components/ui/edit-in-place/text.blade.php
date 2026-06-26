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
    'help' => null,
])

@php
    $value = $value ?? '';
    $inputMode = $inputmode ?? null;
    $inputId = $attributes->get('id') ?? 'edit-in-place-text-'.str()->uuid();
@endphp

<dl
    wire:key="eip-text-{{ $field ?? $attributes->get('id') ?? 'field' }}"
    {{ $attributes->except('id')->class('min-w-0 max-w-full') }}
    x-data="{
        editing: false,
        helpOpen: false,
        editWidth: null,
        focusRecoveryToken: null,
        recoveryKeyListener: null,
        recoveringFocusUntil: 0,
        inputId: @js($inputId),
        inputType: @js($type),
        val: @js((string) $value),
        original: @js((string) $value),
        beginEdit(replacement = null) {
            this.editWidth = Math.ceil(this.$refs.frame.getBoundingClientRect().width);

            if (replacement !== null) {
                this.val = replacement;
            }

            this.editing = true;
            this.$nextTick(() => {
                requestAnimationFrame(() => {
                    this.$refs.input.focus();
                    this.selectInput(replacement === null);
                });
            });
        },
        commit(refocus = false) {
            this.editing = false;
            let pendingSave = null;

            if (this.val !== this.original) {
                this.original = this.val;
                pendingSave = $wire.{{ $saveMethod }}(@js($field), this.val);
            }

            if (refocus) {
                this.recoverTriggerFocus(pendingSave);
            }
        },
        commitAndMove(direction) {
            this.commit();
            this.endFocusRecovery();
            this.$nextTick(() => {
                requestAnimationFrame(() => this.focusRelativeTrigger(direction));
            });
        },
        recoverTriggerFocus(pendingSave = null) {
            this.beginFocusRecovery();
            this.focusTrigger();
            this.queueFocusRecovery();

            Promise.resolve(pendingSave)
                .finally(() => {
                    this.focusTrigger();
                    this.queueFocusRecovery();
                })
                .catch(() => {});
        },
        beginFocusRecovery() {
            const token = (window.__blbEditInPlaceFocusToken || 0) + 1;

            window.__blbEditInPlaceFocusToken = token;
            this.focusRecoveryToken = token;
            this.recoveringFocusUntil = Date.now() + 1200;
            this.recoveryKeyListener = this.recoveryKeyListener || ((event) => this.handleRecoveryKey(event));
            window.removeEventListener('keydown', this.recoveryKeyListener, true);
            window.addEventListener('keydown', this.recoveryKeyListener, true);

            setTimeout(() => {
                if (this.focusRecoveryToken === token) {
                    this.endFocusRecovery();
                }
            }, 1300);
        },
        endFocusRecovery() {
            if (window.__blbEditInPlaceFocusToken === this.focusRecoveryToken) {
                window.__blbEditInPlaceFocusToken = null;
            }

            if (this.recoveryKeyListener) {
                window.removeEventListener('keydown', this.recoveryKeyListener, true);
            }

            this.focusRecoveryToken = null;
            this.recoveringFocusUntil = 0;
        },
        isRecoveringFocus() {
            return this.focusRecoveryToken !== null
                && window.__blbEditInPlaceFocusToken === this.focusRecoveryToken
                && Date.now() <= this.recoveringFocusUntil;
        },
        cancel(refocus = false) {
            this.editing = false;
            this.val = this.original;

            if (refocus) {
                this.focusTrigger();
            }
        },
        queueFocusRecovery() {
            [50, 150, 350, 700].forEach((delay) => {
                setTimeout(() => this.focusTrigger(true), delay);
            });
        },
        focusTrigger(onlyIfFocusWasLost = false) {
            let forceAttempt = ! onlyIfFocusWasLost;

            const applyFocus = () => {
                const forceCurrentAttempt = forceAttempt;

                forceAttempt = false;

                const trigger = this.triggerElement();

                if (! trigger || trigger.offsetParent === null) {
                    return;
                }

                if (! forceCurrentAttempt && ! this.shouldRecoverFocus()) {
                    return;
                }

                if (onlyIfFocusWasLost && ! this.isRecoveringFocus()) {
                    return;
                }

                trigger.focus();
            };

            applyFocus();

            this.$nextTick(() => {
                applyFocus();
                requestAnimationFrame(applyFocus);
            });
        },
        shouldRecoverFocus() {
            const active = document.activeElement;

            return ! active
                || active === document.body
                || active === document.documentElement
                || active === this.inputElement();
        },
        inputElement() {
            return document.getElementById(this.inputId) || this.$refs.input;
        },
        triggerElement() {
            const input = this.inputElement();
            const trigger = input?.previousElementSibling;

            if (trigger?.matches?.('[data-edit-in-place-text-trigger]')) {
                return trigger;
            }

            return this.$refs.trigger;
        },
        handleRecoveryKey(event) {
            if (! this.isRecoveringFocus() || event.defaultPrevented || ! this.shouldRecoverFocus()) {
                return;
            }

            if (['ArrowLeft', 'ArrowUp'].includes(event.key)) {
                event.preventDefault();
                this.focusRelativeTrigger(-1);
                this.endFocusRecovery();

                return;
            }

            if (['ArrowRight', 'ArrowDown'].includes(event.key)) {
                event.preventDefault();
                this.focusRelativeTrigger(1);
                this.endFocusRecovery();
            }
        },
        selectInput(selectAll = true) {
            try {
                if (selectAll) {
                    this.$refs.input.select();

                    return;
                }

                this.$refs.input.setSelectionRange(this.val.length, this.val.length);
            } catch (e) {
                // Some input types, notably number, do not support selection APIs.
            }
        },
        handleReadKey(event) {
            if (['ArrowLeft', 'ArrowUp'].includes(event.key)) {
                event.preventDefault();
                this.focusRelativeTrigger(-1);

                return;
            }

            if (['ArrowRight', 'ArrowDown'].includes(event.key)) {
                event.preventDefault();
                this.focusRelativeTrigger(1);

                return;
            }

            if (['Enter', 'F2'].includes(event.key)) {
                event.preventDefault();
                this.beginEdit();

                return;
            }

            if (event.key === ' ') {
                event.preventDefault();
                this.beginEdit();

                return;
            }

            if (['Backspace', 'Delete'].includes(event.key)) {
                event.preventDefault();
                this.beginEdit('');

                return;
            }

            if (! this.isPrintableEditKey(event)) {
                return;
            }

            event.preventDefault();
            this.beginEdit(event.key);
        },
        isPrintableEditKey(event) {
            if (event.key.length !== 1 || event.ctrlKey || event.metaKey || event.altKey) {
                return false;
            }

            if (this.inputType === 'number') {
                return /^[0-9.+-]$/.test(event.key);
            }

            return true;
        },
        focusRelativeTrigger(direction) {
            const triggers = Array
                .from(document.querySelectorAll('[data-edit-in-place-text-trigger]'))
                .filter((trigger) => ! trigger.disabled && trigger.offsetParent !== null);
            const currentIndex = triggers.indexOf(this.triggerElement());

            if (currentIndex === -1 || triggers.length === 0) {
                return;
            }

            triggers.at((currentIndex + direction + triggers.length) % triggers.length)?.focus();
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

    <dd x-ref="frame" class="relative min-w-0 max-w-full text-sm text-ink">
        <button
            type="button"
            x-ref="trigger"
            data-edit-in-place-text-trigger
            @click="beginEdit()"
            @keydown="handleReadKey($event)"
            x-bind:class="editing ? 'invisible pointer-events-none' : ''"
            x-bind:tabindex="editing ? -1 : null"
            x-bind:aria-hidden="editing.toString()"
            @class([
                'group flex max-w-full min-w-0 cursor-pointer items-center gap-1.5 rounded px-1 py-0.5 -mx-1 text-left hover:bg-surface-subtle',
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
            @keydown.enter.prevent="commit(true)"
            @keydown.escape="cancel(true)"
            @keydown.arrow-up.prevent="commitAndMove(-1)"
            @keydown.arrow-down.prevent="commitAndMove(1)"
            @blur="if (editing) { commit() }"
            x-bind:style="editWidth ? 'width: ' + editWidth + 'px' : null"
            type="{{ $type }}"
            @if ($inputMode) inputmode="{{ $inputMode }}" @endif
            @if ($maxlength) maxlength="{{ $maxlength }}" @endif
            @class([
                'absolute left-0 top-0 block w-full min-w-0 max-w-full box-border px-1 py-0.5 -mx-1 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent',
                'font-mono' => $monospace,
                'tabular-nums' => $tabular,
            ])
        />

        @if ($error)
            <p class="mt-1 text-sm text-status-danger">{{ $error }}</p>
        @endif
    </dd>
</dl>
