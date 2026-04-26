<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Operation\IT\Livewire\Tickets\Create $this */
?>

<div>
    <x-slot name="title">{{ __('New Ticket') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('New Ticket')" :subtitle="__('Submit an IT support request')">
            <x-slot name="actions">
                <x-ui.button variant="ghost" as="a" href="{{ route('it.tickets.index') }}" wire:navigate>
                    <x-icon name="heroicon-o-arrow-left" class="w-4 h-4" />
                    {{ __('Back') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        <x-ui.card>
            <form wire:submit="store" class="space-y-6">
                <x-ui.input
                    id="title"
                    wire:model="title"
                    label="{{ __('Title') }}"
                    type="text"
                    required
                    placeholder="{{ __('Brief description of the issue') }}"
                    :error="$errors->first('title')"
                />

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <x-ui.select id="priority" wire:model="priority" label="{{ __('Priority') }}" :error="$errors->first('priority')">
                        <option value="low">{{ __('Low') }}</option>
                        <option value="medium">{{ __('Medium') }}</option>
                        <option value="high">{{ __('High') }}</option>
                        <option value="critical">{{ __('Critical') }}</option>
                    </x-ui.select>

                    <x-ui.select id="category" wire:model="category" label="{{ __('Category') }}" :error="$errors->first('category')">
                        <option value="">{{ __('Select...') }}</option>
                        <option value="hardware">{{ __('Hardware') }}</option>
                        <option value="software">{{ __('Software') }}</option>
                        <option value="network">{{ __('Network') }}</option>
                        <option value="access">{{ __('Access') }}</option>
                        <option value="other">{{ __('Other') }}</option>
                    </x-ui.select>

                    <x-ui.input
                        id="location"
                        wire:model="location"
                        label="{{ __('Location') }}"
                        type="text"
                        placeholder="{{ __('e.g., Floor 3 - Room 301') }}"
                        :error="$errors->first('location')"
                    />
                </div>

                <x-ui.textarea
                    id="description"
                    wire:model="description"
                    label="{{ __('Description') }}"
                    rows="5"
                    placeholder="{{ __('Describe the issue in detail...') }}"
                    :error="$errors->first('description')"
                />

                <div class="flex items-center gap-4">
                    <x-ui.button type="submit" variant="primary">
                        {{ __('Create Ticket') }}
                    </x-ui.button>
                    <x-ui.button variant="ghost" as="a" href="{{ route('it.tickets.index') }}" wire:navigate>
                        {{ __('Cancel') }}
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</div>
