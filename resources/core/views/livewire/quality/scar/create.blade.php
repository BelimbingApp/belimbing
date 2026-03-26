<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\Quality\Livewire\Scar\Create $this */
?>

<div>
    <x-slot name="title">{{ __('New SCAR') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('New SCAR')" :subtitle="__('Create a supplier corrective action request')">
            <x-slot name="actions">
                <x-ui.button variant="ghost" as="a" href="{{ route('quality.scar.index') }}" wire:navigate>
                    <x-icon name="heroicon-o-arrow-left" class="w-4 h-4" />
                    {{ __('Back') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        <x-ui.card>
            <form wire:submit="store" class="space-y-6">
                @if($ncr)
                    <x-ui.alert variant="info">
                        {{ __('Creating SCAR for NCR :no — :title', ['no' => $ncr->ncr_no, 'title' => $ncr->title]) }}
                    </x-ui.alert>
                    <input type="hidden" wire:model="ncr_id" />
                @endif

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-ui.input
                        id="scar-supplier-name"
                        wire:model="supplier_name"
                        label="{{ __('Supplier Name') }}"
                        type="text"
                        required
                        :error="$errors->first('supplier_name')"
                    />
                    <x-ui.input
                        id="scar-supplier-site"
                        wire:model="supplier_site"
                        label="{{ __('Supplier Site') }}"
                        type="text"
                        :error="$errors->first('supplier_site')"
                    />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <x-ui.input
                        id="scar-contact-name"
                        wire:model="supplier_contact_name"
                        label="{{ __('Contact Name') }}"
                        type="text"
                        :error="$errors->first('supplier_contact_name')"
                    />
                    <x-ui.input
                        id="scar-contact-email"
                        wire:model="supplier_contact_email"
                        label="{{ __('Contact Email') }}"
                        type="email"
                        :error="$errors->first('supplier_contact_email')"
                    />
                    <x-ui.input
                        id="scar-contact-phone"
                        wire:model="supplier_contact_phone"
                        label="{{ __('Contact Phone') }}"
                        type="text"
                        :error="$errors->first('supplier_contact_phone')"
                    />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <x-ui.input
                        id="scar-product-name"
                        wire:model="product_name"
                        label="{{ __('Product Name') }}"
                        type="text"
                        :error="$errors->first('product_name')"
                    />
                    <x-ui.input
                        id="scar-product-code"
                        wire:model="product_code"
                        label="{{ __('Product Code') }}"
                        type="text"
                        :error="$errors->first('product_code')"
                    />
                    <x-ui.input
                        id="scar-po-do-invoice"
                        wire:model="po_do_invoice_no"
                        label="{{ __('PO/DO/Invoice No') }}"
                        type="text"
                        :error="$errors->first('po_do_invoice_no')"
                    />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <x-ui.select id="scar-request-type" wire:model="request_type" label="{{ __('Request Type') }}" :error="$errors->first('request_type')">
                        <option value="">{{ __('Select...') }}</option>
                        @foreach(config('quality.scar_request_types') as $value => $label)
                            <option value="{{ $value }}">{{ __($label) }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.select id="scar-severity" wire:model="severity" label="{{ __('Severity') }}" :error="$errors->first('severity')">
                        <option value="">{{ __('Select...') }}</option>
                        @foreach(config('quality.severity_levels') as $value => $label)
                            <option value="{{ $value }}">{{ __($label) }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.input
                        id="scar-detected-area"
                        wire:model="detected_area"
                        label="{{ __('Detected Area') }}"
                        type="text"
                        :error="$errors->first('detected_area')"
                    />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <x-ui.input
                        id="scar-claim-quantity"
                        wire:model="claim_quantity"
                        label="{{ __('Claim Quantity') }}"
                        type="number"
                        step="0.0001"
                        :error="$errors->first('claim_quantity')"
                    />
                    <x-ui.input
                        id="scar-uom"
                        wire:model="uom"
                        label="{{ __('UOM') }}"
                        type="text"
                        :error="$errors->first('uom')"
                    />
                    <x-ui.input
                        id="scar-claim-value"
                        wire:model="claim_value"
                        label="{{ __('Claim Value') }}"
                        type="number"
                        step="0.01"
                        :error="$errors->first('claim_value')"
                    />
                </div>

                <x-ui.textarea
                    id="scar-problem-description"
                    wire:model="problem_description"
                    label="{{ __('Problem Description') }}"
                    rows="4"
                    placeholder="{{ __('Describe the problem in detail...') }}"
                    :error="$errors->first('problem_description')"
                />

                <div class="flex items-center gap-4">
                    <x-ui.button type="submit" variant="primary">
                        {{ __('Create SCAR') }}
                    </x-ui.button>
                    <x-ui.button variant="ghost" as="a" href="{{ route('quality.scar.index') }}" wire:navigate>
                        {{ __('Cancel') }}
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</div>
