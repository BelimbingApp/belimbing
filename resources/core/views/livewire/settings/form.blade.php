<?php
/** @var \App\Base\Settings\Livewire\SettingsForm $this */
/** @var array<string, mixed> $group */
/** @var string $groupId */
?>

<div>
    <x-slot name="title">{{ $pageTitle }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$pageTitle" :subtitle="$pageSubtitle" />

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
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
