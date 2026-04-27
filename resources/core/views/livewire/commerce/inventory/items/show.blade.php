<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Commerce\Inventory\Livewire\Items\Show $this */
?>

<div>
    <x-slot name="title">{{ $item->title }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$item->title" :subtitle="$item->sku">
            <x-slot name="actions">
                <x-ui.button variant="ghost" as="a" href="{{ route('commerce.inventory.items.index') }}" wire:navigate>
                    <x-icon name="heroicon-o-arrow-left" class="w-4 h-4" />
                    {{ __('Back') }}
                </x-ui.button>
                <x-ui.button variant="primary" as="a" href="{{ route('commerce.inventory.items.edit', $item) }}" wire:navigate>
                    <x-icon name="heroicon-o-pencil-square" class="w-4 h-4" />
                    {{ __('Edit Item') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-6">
                <x-ui.card>
                    <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</dt>
                            <dd class="mt-1">
                                <x-ui.badge :variant="$this->statusVariant($item->status)">{{ __(Illuminate\Support\Str::headline($item->status)) }}</x-ui.badge>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Unit Cost') }}</dt>
                            <dd class="mt-1 text-sm text-ink tabular-nums">{{ $this->formatMoney($item->unit_cost_amount, $item->currency_code) }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Target Price') }}</dt>
                            <dd class="mt-1 text-sm text-ink tabular-nums">{{ $this->formatMoney($item->target_price_amount, $item->currency_code) }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Created') }}</dt>
                            <dd class="mt-1 text-sm text-ink" title="{{ $item->created_at?->format('Y-m-d H:i:s') }}">{{ $item->created_at?->diffForHumans() }}</dd>
                        </div>
                    </dl>

                    <dl class="mt-4 border-t border-border-default pt-4">
                        <dt class="mb-1 text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Description Notes') }}</dt>
                        <dd class="text-sm text-ink whitespace-pre-wrap">{{ $item->description ?: __('No notes captured yet.') }}</dd>
                    </dl>
                </x-ui.card>
            </div>

            <div class="space-y-6">
                <x-ui.card>
                    <h2 class="mb-3 text-base font-medium tracking-tight text-ink">{{ __('Next Workbench Surfaces') }}</h2>
                    <div class="space-y-3 text-sm text-muted">
                        <p>{{ __('Photos, catalog attributes, Lara drafts, and marketplace listings will attach to this durable item record in later slices.') }}</p>
                        <p>{{ __('For now, this page gives operators a stable place to review item facts before richer workflows arrive.') }}</p>
                    </div>
                </x-ui.card>
            </div>
        </div>
    </div>
</div>
