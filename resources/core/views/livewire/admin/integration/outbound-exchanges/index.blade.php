<?php
/** @var \App\Base\Integration\Livewire\OutboundExchanges\Index $this */
?>
<div>
    <x-slot name="title">{{ __('Outbound Exchanges') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Outbound Exchanges')" :subtitle="__('Recorded requests and responses between BLB and external systems.')">
            <x-slot name="actions">
                @if($canDelete)
                    <x-ui.button
                        variant="ghost"
                        wire:click="cleanupPayloads"
                        wire:confirm="{{ __('Clean retained payload previews whose retention period has elapsed? Exchange metadata will remain.') }}"
                    >
                        <x-icon name="heroicon-o-archive-box-x-mark" class="h-4 w-4" />
                        {{ __('Cleanup Payloads') }}
                    </x-ui.button>
                @endif
            </x-slot>
        </x-ui.page-header>

        @if($statusMessage)
            <x-ui.alert :variant="$statusVariant ?? 'info'">{{ $statusMessage }}</x-ui.alert>
        @endif

        <x-ui.card>
            <div class="grid gap-3 lg:grid-cols-4">
                <div class="lg:col-span-2">
                    <x-ui.search-input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search ID, correlation, endpoint, or error...') }}" />
                </div>
                <x-ui.select wire:model.live="system" :label="__('System')">
                    <option value="">{{ __('All systems') }}</option>
                    @foreach($systems as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select wire:model.live="provider" :label="__('Provider')">
                    <option value="">{{ __('All providers') }}</option>
                    @foreach($providers as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select wire:model.live="operation" :label="__('Operation')">
                    <option value="">{{ __('All operations') }}</option>
                    @foreach($operations as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select wire:model.live="transport" :label="__('Transport')">
                    <option value="">{{ __('All transports') }}</option>
                    @foreach($transports as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select wire:model.live="protocol" :label="__('Protocol')">
                    <option value="">{{ __('All protocols') }}</option>
                    @foreach($protocols as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select wire:model.live="outcome" :label="__('Outcome')">
                    <option value="">{{ __('All outcomes') }}</option>
                    @foreach($outcomes as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select wire:model.live="ownerType" :label="__('Owner')">
                    <option value="">{{ __('All owners') }}</option>
                    @foreach($ownerTypes as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select wire:model.live="since" :label="__('Time Range')">
                    <option value="">{{ __('All time') }}</option>
                    <option value="1">{{ __('Last hour') }}</option>
                    <option value="24">{{ __('Last 24 hours') }}</option>
                    <option value="168">{{ __('Last 7 days') }}</option>
                    <option value="720">{{ __('Last 30 days') }}</option>
                </x-ui.select>
                <x-ui.input wire:model.live.debounce.300ms="ownerId" :label="__('Owner ID')" inputmode="numeric" />
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Exchange') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('System') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Operation') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Protocol') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Duration') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Occurred') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-default">
                        @forelse($exchanges as $exchange)
                            <tr wire:key="outbound-exchange-{{ $exchange->id }}" class="hover:bg-surface-subtle/50">
                                <td class="px-table-cell-x py-table-cell-y">
                                    <a href="{{ route('admin.integration.outbound-exchanges.show', $exchange) }}" class="font-mono text-xs text-accent hover:underline" wire:navigate>{{ $exchange->id }}</a>
                                    <div class="mt-1 max-w-xs truncate text-xs text-muted">{{ $exchange->endpoint }}</div>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y">
                                    <div class="font-medium text-ink">{{ $exchange->system }}</div>
                                    <div class="text-xs text-muted">{{ $exchange->provider ?? __('No provider') }}</div>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y">
                                    <div class="font-mono text-xs text-ink">{{ $exchange->operation }}</div>
                                    @if($exchange->protocol_operation)
                                        <div class="mt-1 text-xs text-muted">{{ $exchange->protocol_operation }}</div>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <x-ui.badge>{{ $exchange->transport }}</x-ui.badge>
                                    <x-ui.badge>{{ $exchange->protocol }}</x-ui.badge>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <x-ui.badge :variant="$exchange->outcome === 'success' ? 'success' : 'danger'">{{ $exchange->outcome }}</x-ui.badge>
                                    <span class="ml-2 text-xs text-muted tabular-nums">{{ $exchange->response_status ?? 'n/a' }}</span>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-ink">{{ $exchange->duration_ms ?? 'n/a' }} ms</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-muted tabular-nums">
                                    <x-ui.datetime :value="$exchange->occurred_at" />
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-right">
                                    <x-ui.icon-action-group>
                                        <x-ui.icon-action
                                            icon="heroicon-o-eye"
                                            :label="__('Inspect exchange')"
                                            :title="__('Inspect')"
                                            href="{{ route('admin.integration.outbound-exchanges.show', $exchange) }}"
                                            wire:navigate
                                        />
                                        @if($canDelete)
                                            <x-ui.icon-action
                                                icon="heroicon-o-trash"
                                                :label="__('Delete exchange')"
                                                :title="__('Delete')"
                                                wire:click="deleteExchange('{{ $exchange->id }}')"
                                                wire:confirm="{{ __('Delete this exchange record?') }}"
                                            />
                                        @endif
                                    </x-ui.icon-action-group>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No outbound exchanges found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $exchanges->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
