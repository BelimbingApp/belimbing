<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Commerce\Inventory\Livewire\Items\Create $this */
?>

<div>
    <x-slot name="title">{{ __('New Item') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('New Item')" :subtitle="__('Capture the first durable record for a sellable item.')">
            <x-slot name="actions">
                <x-ui.button variant="ghost" as="a" href="{{ route('commerce.inventory.items.index') }}" wire:navigate>
                    <x-icon name="heroicon-o-arrow-left" class="w-4 h-4" />
                    {{ __('Back') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        @if (session('error'))
            <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <form wire:submit="store" class="space-y-6">
                <x-ui.input
                    id="inventory-item-title"
                    wire:model="title"
                    label="{{ __('Title') }}"
                    type="text"
                    required
                    placeholder="{{ __('e.g., 2008 Honda Civic driver side headlight') }}"
                    :error="$errors->first('title')"
                />

                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <x-ui.select id="inventory-item-status" wire:model="status" label="{{ __('Status') }}" :error="$errors->first('status')">
                        @foreach ($statuses as $statusOption)
                            <option value="{{ $statusOption }}">{{ __(Illuminate\Support\Str::headline($statusOption)) }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.input
                        id="inventory-item-unit-cost-amount"
                        wire:model="unitCostAmount"
                        label="{{ __('Unit Cost') }}"
                        type="text"
                        inputmode="decimal"
                        placeholder="{{ __('40.00') }}"
                        :error="$errors->first('unitCostAmount')"
                    />

                    <x-ui.input
                        id="inventory-item-target-price-amount"
                        wire:model="targetPriceAmount"
                        label="{{ __('Target Price') }}"
                        type="text"
                        inputmode="decimal"
                        placeholder="{{ __('120.00') }}"
                        :error="$errors->first('targetPriceAmount')"
                    />

                    <x-ui.input
                        id="inventory-item-currency-code"
                        wire:model="currencyCode"
                        label="{{ __('Currency') }}"
                        type="text"
                        maxlength="3"
                        required
                        :error="$errors->first('currencyCode')"
                    />
                </div>

                <x-ui.textarea
                    id="inventory-item-description"
                    wire:model="description"
                    label="{{ __('Description Notes') }}"
                    rows="5"
                    placeholder="{{ __('Condition, fitment, defects, identifiers, variant notes...') }}"
                    :error="$errors->first('description')"
                />

                <div class="rounded-2xl border border-dashed border-border-default bg-surface-subtle p-card-inner">
                    <p class="text-sm font-medium text-ink">{{ __('Photos are next.') }}</p>
                    <p class="mt-1 text-sm text-muted">{{ __('This first slice creates the durable item record. Raw photo upload, derived cleanup images, and Lara drafts will plug into this workbench in later slices.') }}</p>
                </div>

                <div class="flex items-center gap-4">
                    <x-ui.button type="submit" variant="primary">
                        {{ __('Create Item') }}
                    </x-ui.button>
                    <x-ui.button variant="ghost" as="a" href="{{ route('commerce.inventory.items.index') }}" wire:navigate>
                        {{ __('Cancel') }}
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</div>
