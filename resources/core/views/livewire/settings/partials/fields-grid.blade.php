<?php

use App\Base\Settings\Livewire\SettingsForm;

/** @var SettingsForm $this */
/** @var array<string, mixed> $group */
?>

<div class="grid grid-cols-1 gap-4 md:grid-cols-2">
    @foreach ($group['fields'] as $field)
        @php($key = $field['key'])
        @php($formKey = str_replace('.', '__', $key))
        @php($id = 'setting-'.str_replace(['.', '_'], '-', $key))
        @php($isAdvanced = (bool) ($field['advanced'] ?? false))

        @if ($isAdvanced)
            <details class="md:col-span-2 rounded-2xl border border-border-default bg-surface-subtle/40 p-4">
                <summary class="cursor-pointer text-sm font-medium text-ink marker:text-muted">
                    {{ __($field['advanced_label'] ?? 'Advanced settings') }}
                </summary>
                @if ($field['advanced_help'] ?? null)
                    <p class="mt-2 text-sm text-muted">{{ __($field['advanced_help']) }}</p>
                @endif
                <div class="mt-4">
        @endif

        @if (($field['type'] ?? 'text') === 'readonly')
            @php($readonlyValue = $this->fieldValue($field))
            <div class="md:col-span-2">
                <div class="space-y-1.5" x-data="{ copied: false }">
                    <div class="text-[11px] uppercase tracking-wider font-semibold text-muted">
                        {{ __($field['label']) }}
                    </div>

                    <div class="flex flex-col gap-2 rounded-2xl border border-border-default bg-surface-subtle px-3 py-2 sm:flex-row sm:items-center sm:justify-between">
                        <span id="{{ $id }}" class="break-all font-mono text-sm text-ink">{{ $readonlyValue }}</span>
                        <button
                            type="button"
                            class="shrink-0 rounded-md p-1 text-muted transition-colors hover:text-accent focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/40"
                            @click="navigator.clipboard.writeText(@js($readonlyValue)); copied = true; setTimeout(() => copied = false, 1500);"
                            x-bind:aria-label="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'"
                            title="{{ __('Copy') }}"
                        >
                            <x-icon name="mdi-content-copy" class="h-4 w-4" />
                            <span class="sr-only" x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'"></span>
                        </button>
                    </div>

                    @if ($field['help'] ?? null)
                        <x-ui.field-help :hint="__($field['help'])" />
                    @endif
                </div>
            </div>
        @elseif (($field['type'] ?? 'text') === 'select')
            <x-ui.select
                id="{{ $id }}"
                wire:model="values.{{ $formKey }}"
                label="{{ __($field['label']) }}"
                :help="__($field['help'])"
                :error="$errors->first('values.' . $formKey)"
            >
                @foreach (($field['options'] ?? []) as $optionValue => $optionLabel)
                    <option value="{{ $optionValue }}">{{ __($optionLabel) }}</option>
                @endforeach
            </x-ui.select>
        @elseif (($field['type'] ?? 'text') === 'textarea')
            <div class="md:col-span-2">
                <x-ui.textarea
                    id="{{ $id }}"
                    wire:model="values.{{ $formKey }}"
                    label="{{ __($field['label']) }}"
                    rows="4"
                    placeholder="{{ __($field['placeholder'] ?? '') }}"
                    :help="__($field['help'])"
                    :error="$errors->first('values.' . $formKey)"
                />
            </div>
        @elseif (($field['type'] ?? 'text') === 'checkbox-list')
            <div class="md:col-span-2 space-y-2">
                <div class="text-[11px] uppercase tracking-wider font-semibold text-muted">
                    {{ __($field['label']) }}
                </div>

                <div class="grid gap-3 rounded-2xl border border-border-default bg-surface-card p-4 lg:grid-cols-2">
                    @foreach (($field['options'] ?? []) as $optionValue => $option)
                        <x-ui.checkbox
                            id="{{ $id }}-{{ \Illuminate\Support\Str::slug($optionValue) }}"
                            wire:model="values.{{ $formKey }}"
                            value="{{ $optionValue }}"
                            :label="__($option['label'] ?? $optionValue)"
                            :help="__($option['help'] ?? '')"
                        />
                    @endforeach
                </div>

                @if ($errors->first('values.' . $formKey))
                    <p class="text-sm text-status-danger">{{ $errors->first('values.' . $formKey) }}</p>
                @elseif($field['help'] ?? null)
                    <x-ui.field-help :hint="__($field['help'])" />
                @endif
            </div>
        @elseif (($field['type'] ?? 'text') === 'password')
            <div class="space-y-3">
                <x-ui.secret-input
                    id="{{ $id }}"
                    wire:model="values.{{ $formKey }}"
                    value="{{ (string) ($values[$formKey] ?? '') }}"
                    label="{{ __($field['label']) }}"
                    :placeholder="$this->hasEncryptedValue($field) ? '' : __($field['placeholder'] ?? '')"
                    :help="filled($field['help'] ?? null) ? __($field['help']) : null"
                    :error="$errors->first('values.' . $formKey)"
                    :has-value="$this->hasEncryptedValue($field)"
                    :show-reveal-button="(bool) ($field['show_reveal_button'] ?? false)"
                    :saved-mask="$this->savedSecretMask($field)"
                    autocomplete="new-password"
                    autocapitalize="none"
                    spellcheck="false"
                />

                @if(($field['actions'] ?? []) !== [])
                    <div class="flex flex-wrap items-center gap-2">
                        @foreach($field['actions'] as $action)
                            @if(! ($action['when_saved'] ?? false) || $this->hasEncryptedValue($field))
                                @if($action['confirm'] ?? null)
                                    <x-ui.button
                                        type="button"
                                        :variant="$action['variant'] ?? 'control'"
                                        size="sm"
                                        wire:click="{{ $action['method'] }}"
                                        wire:loading.attr="disabled"
                                        wire:target="{{ $action['method'] }}"
                                        wire:confirm="{{ __($action['confirm']) }}"
                                    >
                                        @if($action['icon'] ?? null)
                                            <x-icon :name="$action['icon']" class="h-4 w-4" />
                                        @endif
                                        <span wire:loading.remove wire:target="{{ $action['method'] }}">{{ __($action['label']) }}</span>
                                        <span wire:loading wire:target="{{ $action['method'] }}">{{ __('Working…') }}</span>
                                    </x-ui.button>
                                @else
                                    <x-ui.button
                                        type="button"
                                        :variant="$action['variant'] ?? 'control'"
                                        size="sm"
                                        wire:click="{{ $action['method'] }}"
                                        wire:loading.attr="disabled"
                                        wire:target="{{ $action['method'] }}"
                                    >
                                        @if($action['icon'] ?? null)
                                            <x-icon :name="$action['icon']" class="h-4 w-4" />
                                        @endif
                                        <span wire:loading.remove wire:target="{{ $action['method'] }}">{{ __($action['label']) }}</span>
                                        <span wire:loading wire:target="{{ $action['method'] }}">{{ __('Working…') }}</span>
                                    </x-ui.button>
                                @endif
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>
        @else
            <x-ui.input
                id="{{ $id }}"
                wire:model="values.{{ $formKey }}"
                label="{{ __($field['label']) }}"
                type="text"
                placeholder="{{ __($field['placeholder'] ?? '') }}"
                :help="__($field['help'])"
                :error="$errors->first('values.' . $formKey)"
            />
        @endif

        @if ($isAdvanced)
                </div>
            </details>
        @endif
    @endforeach
</div>
