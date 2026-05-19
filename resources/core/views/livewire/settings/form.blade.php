<?php

use App\Base\Settings\Livewire\SettingsForm;

/** @var SettingsForm $this */
/** @var array<string, mixed> $group */
/** @var string $groupId */
?>

<div>
    <x-slot name="title">{{ $pageTitle }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$pageTitle" :subtitle="$pageSubtitle">
            @if ($group['help_title'] ?? null)
                <x-slot name="help">
                    <div class="space-y-3">
                        <div>
                            <p class="text-sm font-medium text-ink">{{ __($group['help_title']) }}</p>
                            @if ($group['help_intro'] ?? null)
                                <p class="mt-1 text-sm text-muted">{{ __($group['help_intro']) }}</p>
                            @endif
                        </div>

                        @if (($group['help_steps'] ?? []) !== [])
                            <ol class="list-decimal space-y-1.5 pl-5 text-sm text-muted">
                                @foreach ($group['help_steps'] as $step)
                                    @if (is_array($step))
                                        <li>
                                            {{ __($step['before_link'] ?? '') }}
                                            <a href="{{ $step['url'] }}" target="_blank" rel="noreferrer" class="text-accent hover:underline">
                                                {{ __($step['link_label']) }}
                                            </a>
                                            {{ __($step['after_link'] ?? '') }}
                                        </li>
                                    @else
                                        <li>{{ __($step) }}</li>
                                    @endif
                                @endforeach
                            </ol>
                        @endif
                    </div>
                </x-slot>
            @endif
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
        @endif

        @if (method_exists($this, 'testConnection'))
            <x-ui.card>
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0 space-y-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Connection test') }}</h2>
                            <x-ui.badge :variant="$this->connectionTestBadgeVariant()">
                                {{ __(\Illuminate\Support\Str::headline($this->connectionTest['status'] ?? 'not tested')) }}
                            </x-ui.badge>
                        </div>
                        <p class="text-sm text-muted">
                            {{ __('Verify that the saved eBay credentials, OAuth grant, selected environment, and Sell Account API access work before publishing or syncing setup data.') }}
                        </p>

                        @if ($this->connectionTest['message'] ?? null)
                            <x-ui.alert :variant="$this->connectionTestAlertVariant()" class="mt-3">
                                {{ $this->connectionTest['message'] }}
                            </x-ui.alert>
                        @else
                            <p class="text-sm text-muted">{{ __('Not tested yet.') }}</p>
                        @endif

                        @if ($this->connectionTest['tested_at'] ?? null)
                            <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
                                <div>
                                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Last checked') }}</dt>
                                    <dd class="mt-1 text-ink">{{ \Illuminate\Support\Carbon::parse($this->connectionTest['tested_at'])->diffForHumans() }}</dd>
                                </div>
                                @if ($this->connectionTest['http_status'] ?? null)
                                    <div>
                                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('HTTP status') }}</dt>
                                        <dd class="mt-1 font-mono text-ink">{{ $this->connectionTest['http_status'] }}</dd>
                                    </div>
                                @endif
                                @if ($this->connectionTest['exchange_id'] ?? null)
                                    <div>
                                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Exchange') }}</dt>
                                        <dd class="mt-1 font-mono text-ink">{{ $this->connectionTest['exchange_id'] }}</dd>
                                    </div>
                                @endif
                            </dl>
                        @endif
                    </div>

                    <div class="flex shrink-0 flex-wrap gap-2">
                        @if (method_exists($this, 'connect'))
                            <x-ui.button type="button" variant="primary" wire:click="connect" wire:loading.attr="disabled" wire:target="connect">
                                <x-icon name="heroicon-o-link" class="h-4 w-4" />
                                {{ method_exists($this, 'connectButtonLabel') ? $this->connectButtonLabel() : __('Connect eBay') }}
                            </x-ui.button>
                        @endif
                        <x-ui.button type="button" variant="outline" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection">
                            <x-icon name="heroicon-o-signal" class="h-4 w-4" />
                            <span wire:loading.remove wire:target="testConnection">{{ __('Test connection') }}</span>
                            <span wire:loading wire:target="testConnection">{{ __('Testing...') }}</span>
                        </x-ui.button>
                    </div>
                </div>
            </x-ui.card>
        @endif

        <form wire:submit="save" class="space-y-6">
            @if (($group['fields'] ?? []) === [])
                <x-ui.card>
                    <p class="text-sm text-muted">{{ __('No editable settings are registered for this page.') }}</p>
                </x-ui.card>
            @else
                <x-ui.card wire:key="settings-group-{{ $groupId }}">
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
                                <div class="md:col-span-2">
                                    <x-ui.input
                                        id="{{ $id }}"
                                        value="{{ $this->fieldValue($field) }}"
                                        label="{{ __($field['label']) }}"
                                        :help="__($field['help'])"
                                        readonly
                                    />
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
                                <x-ui.secret-input
                                    id="{{ $id }}"
                                    wire:model="values.{{ $formKey }}"
                                    label="{{ __($field['label']) }}"
                                    placeholder="{{ __($field['placeholder'] ?? '') }}"
                                    :help="__($field['help'])"
                                    :error="$errors->first('values.' . $formKey)"
                                    :has-value="$this->hasEncryptedValue($field)"
                                    :saved-label="__('Current value:')"
                                    :saved-value-preview="$this->encryptedValuePreview($field)"
                                />
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
                </x-ui.card>
            @endif

            <div class="flex items-center gap-3">
                <x-ui.button type="submit" variant="primary">
                    <x-icon name="heroicon-o-check" class="h-4 w-4" />
                    {{ __('Save Settings') }}
                </x-ui.button>
            </div>
        </form>
    </div>
</div>
