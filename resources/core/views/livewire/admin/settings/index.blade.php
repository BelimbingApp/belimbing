<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Base\Settings\Livewire\Admin\Index $this */
?>

<div>
    <x-slot name="title">{{ $pageTitle }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$pageTitle" :subtitle="$pageSubtitle" />

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        <form wire:submit="save" class="space-y-6">
            @if ($groups === [])
                <x-ui.card>
                    <p class="text-sm text-muted">{{ __('No editable settings are registered for this page.') }}</p>
                </x-ui.card>
            @endif

            @foreach ($groups as $groupId => $group)
                <x-ui.card wire:key="settings-group-{{ $groupId }}">
                    <div class="mb-5">
                        <h2 class="text-base font-medium tracking-tight text-ink">{{ __($group['label']) }}</h2>
                        <p class="mt-1 text-sm text-muted">{{ __($group['description']) }}</p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        @foreach ($group['fields'] as $field)
                            @php($key = $field['key'])
                            @php($formKey = str_replace('.', '__', $key))
                            @php($id = 'setting-'.str_replace(['.', '_'], '-', $key))

                            @if (($field['type'] ?? 'text') === 'select')
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
                            @else
                                <x-ui.input
                                    id="{{ $id }}"
                                    wire:model="values.{{ $formKey }}"
                                    label="{{ __($field['label']) }}"
                                    type="{{ ($field['type'] ?? 'text') === 'password' ? 'password' : 'text' }}"
                                    placeholder="{{ __($field['placeholder'] ?? '') }}"
                                    :help="__($field['help'])"
                                    :error="$errors->first('values.' . $formKey)"
                                />
                            @endif
                        @endforeach
                    </div>
                </x-ui.card>
            @endforeach

            <div class="flex items-center gap-3">
                <x-ui.button type="submit" variant="primary">
                    <x-icon name="heroicon-o-check" class="h-4 w-4" />
                    {{ __('Save Settings') }}
                </x-ui.button>
            </div>
        </form>
    </div>
</div>
