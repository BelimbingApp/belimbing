<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\Quality\Livewire\Scar\Index $this */
?>

<div>
    <x-slot name="title">{{ __('SCAR') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Supplier Corrective Action Requests')" :subtitle="__('Manage supplier SCARs')">
            <x-slot name="actions">
                <x-ui.button variant="primary" as="a" href="{{ route('quality.ncr.index') }}" wire:navigate>
                    <x-icon name="heroicon-o-plus" class="h-4 w-4" />
                    {{ __('Create from NCR') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <div class="mb-2 flex flex-col sm:flex-row gap-2">
                <div class="flex-1">
                    <x-ui.search-input
                        id="scar-search"
                        wire:model.live.debounce.300ms="search"
                        aria-label="{{ __('Search SCARs') }}"
                        placeholder="{{ __('Search by SCAR number, supplier, or product...') }}"
                    />
                </div>
                <div class="w-full sm:w-48">
                    <x-ui.select id="status-filter" wire:model.live="statusFilter">
                        <option value="">{{ __('All Statuses') }}</option>
                        <option value="draft">{{ __('Draft') }}</option>
                        <option value="issued">{{ __('Issued') }}</option>
                        <option value="acknowledged">{{ __('Acknowledged') }}</option>
                        <option value="containment_submitted">{{ __('Containment Submitted') }}</option>
                        <option value="under_investigation">{{ __('Under Investigation') }}</option>
                        <option value="response_submitted">{{ __('Response Submitted') }}</option>
                        <option value="under_review">{{ __('Under Review') }}</option>
                        <option value="action_required">{{ __('Action Required') }}</option>
                        <option value="verification_pending">{{ __('Verification Pending') }}</option>
                        <option value="closed">{{ __('Closed') }}</option>
                        <option value="rejected">{{ __('Rejected') }}</option>
                        <option value="cancelled">{{ __('Cancelled') }}</option>
                    </x-ui.select>
                </div>
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <x-ui.sortable-th
                                column="scar_no"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('scar_no')"
                                :label="__('SCAR No')"
                            />
                            <x-ui.sortable-th
                                column="ncr_no"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('ncr_no')"
                                :label="__('NCR')"
                            />
                            <x-ui.sortable-th
                                column="supplier_name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('supplier_name')"
                                :label="__('Supplier')"
                            />
                            <x-ui.sortable-th
                                column="product_name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('product_name')"
                                :label="__('Product')"
                            />
                            <x-ui.sortable-th
                                column="status"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('status')"
                                :label="__('Status')"
                            />
                            <x-ui.sortable-th
                                column="owner_name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('owner_name')"
                                :label="__('Owner')"
                            />
                            <x-ui.sortable-th
                                column="created_at"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('created_at')"
                                :label="__('Created')"
                            />
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($scars as $scar)
                            <tr wire:key="scar-{{ $scar->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $scar->scar_no }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <a href="{{ route('quality.ncr.show', $scar->ncr) }}" wire:navigate class="text-sm text-accent hover:underline">{{ $scar->ncr?->ncr_no ?? '—' }}</a>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y">
                                    <a href="{{ route('quality.scar.show', $scar) }}" wire:navigate class="text-sm font-medium text-accent hover:underline">{{ $scar->supplier_name }}</a>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $scar->product_name ?? '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <x-ui.badge :variant="$this->statusVariant($scar->status)">{{ str_replace('_', ' ', ucfirst($scar->status)) }}</x-ui.badge>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $scar->issueOwner?->name ?? '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $scar->created_at?->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No SCARs found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                {{ $scars->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
