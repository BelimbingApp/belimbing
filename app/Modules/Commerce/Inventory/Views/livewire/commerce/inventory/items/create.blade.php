<?php

use App\Modules\Commerce\Inventory\Livewire\Items\Create;

/** @var Create $this */
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
                <div>
                    <x-ui.input
                        id="inventory-item-sku"
                        wire:model.live.debounce.300ms="sku"
                        label="{{ __('SKU') }}"
                        type="text"
                        uppercase
                        maxlength="64"
                        required
                        placeholder="{{ __('e.g., HAM-HEADLIGHT-0001') }}"
                        :help="$skuAvailable === null ? __('Seller-controlled item code, required and unique within the operating company.') : null"
                        :error="$errors->first('sku')"
                    />

                    @if ($skuAvailable === true)
                        <p class="mt-1 text-sm text-status-success">{{ __('SKU is available for this company.') }}</p>
                    @elseif ($skuAvailable === false)
                        <p class="mt-1 text-sm text-status-danger">{{ __('SKU is already used by this company.') }}</p>
                    @endif
                </div>

                <x-ui.input
                    id="inventory-item-title"
                    wire:model="title"
                    label="{{ __('Title') }}"
                    type="text"
                    required
                    placeholder="{{ __('e.g., 2008 Honda Civic driver side headlight') }}"
                    :help="__('Buyer-facing item name used as the listing title when the item is published.')"
                    :error="$errors->first('title')"
                />

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-ui.select
                        id="inventory-item-category"
                        wire:model.live="categoryId"
                        label="{{ __('Category') }}"
                        :help="__('Broad catalog group for this item.')"
                        :error="$errors->first('categoryId')"
                    >
                        <option value="">{{ __('No category') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->path_label }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.select
                        id="inventory-item-template"
                        wire:model.live="productTemplateId"
                        label="{{ __('Template') }}"
                        :help="__('Item type that decides which catalog attributes apply.')"
                        :error="$errors->first('productTemplateId')"
                    >
                        <option value="">{{ __('No template') }}</option>
                        @foreach ($productTemplates as $template)
                            <option value="{{ $template->id }}">
                                {{ $template->name }}
                                @if ($template->category)
                                    {{ __('(:category)', ['category' => $template->category->path_label]) }}
                                @endif
                            </option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <x-ui.select
                        id="inventory-item-status"
                        wire:model="status"
                        label="{{ __('Status') }}"
                        :help="__('Lifecycle stage from draft through ready, listed, sold, and archived.')"
                        :error="$errors->first('status')"
                    >
                        @foreach ($statuses as $statusOption)
                            <option value="{{ $statusOption }}">{{ __(Illuminate\Support\Str::headline($statusOption)) }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.input
                        id="inventory-item-quantity-on-hand"
                        wire:model="quantityOnHand"
                        label="{{ __('Qty') }}"
                        type="number"
                        min="0"
                        required
                        :help="__('Units available for this SKU or tracked item. Use 1 for one-off used parts.')"
                        :error="$errors->first('quantityOnHand')"
                    />

                    <x-ui.input
                        id="inventory-item-storage-location"
                        wire:model="storageLocation"
                        label="{{ __('Storage location') }}"
                        type="text"
                        placeholder="{{ __('e.g., Shelf A-03') }}"
                        :help="__('Internal place to find this stock.')"
                        :error="$errors->first('storageLocation')"
                    />
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">

                    <x-ui.input
                        id="inventory-item-unit-cost-amount"
                        wire:model="unitCostAmount"
                        label="{{ __('Unit Cost') }}"
                        type="text"
                        inputmode="decimal"
                        placeholder="{{ __('40.00') }}"
                        :help="__('Private acquisition cost. Used later to calculate gross margin.')"
                        :error="$errors->first('unitCostAmount')"
                    />

                    <x-ui.input
                        id="inventory-item-target-price-amount"
                        wire:model="targetPriceAmount"
                        label="{{ __('Target Price') }}"
                        type="text"
                        inputmode="decimal"
                        placeholder="{{ __('120.00') }}"
                        :help="__('Intended selling price and default listing price until a real sale is recorded.')"
                        :error="$errors->first('targetPriceAmount')"
                    />

                    <x-ui.currency-combobox
                        id="inventory-item-currency-code"
                        wire:model="currencyCode"
                        :label="__('Currency')"
                        required
                        :error="$errors->first('currencyCode')"
                    />
                </div>

                <x-ui.textarea
                    id="inventory-item-notes"
                    wire:model="notes"
                    label="{{ __('Notes') }}"
                    rows="5"
                    placeholder="{{ __('Condition, fitment, defects, identifiers, variant notes...') }}"
                    :help="__('Private working notes. Never published to buyers or sent to a marketplace.')"
                    :error="$errors->first('notes')"
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
