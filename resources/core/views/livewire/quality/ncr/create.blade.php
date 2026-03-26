<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\Quality\Livewire\Ncr\Create $this */
?>

<div>
    <x-slot name="title">{{ __('New NCR') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('New NCR')" :subtitle="__('Log a nonconformance report')">
            <x-slot name="actions">
                <x-ui.button variant="ghost" as="a" href="{{ route('quality.ncr.index') }}" wire:navigate>
                    <x-icon name="heroicon-o-arrow-left" class="w-4 h-4" />
                    {{ __('Back') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        <x-ui.card>
            <form wire:submit="store" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-ui.select id="ncr-kind" wire:model="ncr_kind" label="{{ __('NCR Kind') }}" required :error="$errors->first('ncr_kind')">
                        @foreach(config('quality.ncr_kinds') as $value => $label)
                            <option value="{{ $value }}">{{ __($label) }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.select id="ncr-severity" wire:model="severity" label="{{ __('Severity') }}" :error="$errors->first('severity')">
                        <option value="">{{ __('Select...') }}</option>
                        @foreach(config('quality.severity_levels') as $value => $label)
                            <option value="{{ $value }}">{{ __($label) }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <x-ui.input
                    id="ncr-title"
                    wire:model="title"
                    label="{{ __('Title') }}"
                    type="text"
                    required
                    placeholder="{{ __('Brief description of the nonconformance') }}"
                    :error="$errors->first('title')"
                />

                <x-ui.input
                    id="ncr-classification"
                    wire:model="classification"
                    label="{{ __('Classification') }}"
                    type="text"
                    placeholder="{{ __('Defect type or category') }}"
                    :error="$errors->first('classification')"
                />

                <x-ui.textarea
                    id="ncr-summary"
                    wire:model="summary"
                    label="{{ __('Summary') }}"
                    rows="4"
                    placeholder="{{ __('Detailed description of the issue...') }}"
                    :error="$errors->first('summary')"
                />

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-ui.input
                        id="ncr-product-name"
                        wire:model="product_name"
                        label="{{ __('Product Name') }}"
                        type="text"
                        :error="$errors->first('product_name')"
                    />
                    <x-ui.input
                        id="ncr-product-code"
                        wire:model="product_code"
                        label="{{ __('Product Code') }}"
                        type="text"
                        :error="$errors->first('product_code')"
                    />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <x-ui.input
                        id="ncr-reported-by-name"
                        wire:model="reported_by_name"
                        label="{{ __('Reported By') }}"
                        type="text"
                        required
                        :error="$errors->first('reported_by_name')"
                    />
                    <x-ui.input
                        id="ncr-reported-by-email"
                        wire:model="reported_by_email"
                        label="{{ __('Reporter Email') }}"
                        type="email"
                        :error="$errors->first('reported_by_email')"
                    />
                    <x-ui.input
                        id="ncr-source"
                        wire:model="source"
                        label="{{ __('Source') }}"
                        type="text"
                        placeholder="{{ __('e.g., manual, email, inspection') }}"
                        :error="$errors->first('source')"
                    />
                </div>

                <div class="flex items-center gap-4">
                    <x-ui.button type="submit" variant="primary">
                        {{ __('Create NCR') }}
                    </x-ui.button>
                    <x-ui.button variant="ghost" as="a" href="{{ route('quality.ncr.index') }}" wire:navigate>
                        {{ __('Cancel') }}
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</div>
