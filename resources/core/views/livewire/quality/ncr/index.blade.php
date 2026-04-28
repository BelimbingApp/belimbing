<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\Quality\Livewire\Ncr\Index $this */
?>

<div>
    <x-slot name="title">{{ __('NCR') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Nonconformance Reports')" :subtitle="__('Manage quality NCRs')">
            <x-slot name="actions">
                <x-ui.button variant="primary" as="a" href="{{ route('quality.ncr.create') }}" wire:navigate>
                    <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                    {{ __('New NCR') }}
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
                        id="ncr-search"
                        wire:model.live.debounce.300ms="search"
                        aria-label="{{ __('Search NCRs') }}"
                        placeholder="{{ __('Search by NCR number, title, or reporter...') }}"
                    />
                </div>
                <div class="w-full sm:w-48">
                    <x-ui.select id="kind-filter" wire:model.live="kindFilter">
                        <option value="">{{ __('All Kinds') }}</option>
                        @foreach(config('quality.ncr_kinds') as $value => $label)
                            <option value="{{ $value }}">{{ __($label) }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
                <div class="w-full sm:w-48">
                    <x-ui.select id="status-filter" wire:model.live="statusFilter">
                        <option value="">{{ __('All Statuses') }}</option>
                        <option value="open">{{ __('Open') }}</option>
                        <option value="under_triage">{{ __('Under Triage') }}</option>
                        <option value="assigned">{{ __('Assigned') }}</option>
                        <option value="in_progress">{{ __('In Progress') }}</option>
                        <option value="under_review">{{ __('Under Review') }}</option>
                        <option value="verified">{{ __('Verified') }}</option>
                        <option value="closed">{{ __('Closed') }}</option>
                        <option value="rejected">{{ __('Rejected') }}</option>
                    </x-ui.select>
                </div>
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <x-ui.sortable-th
                                column="ncr_no"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('ncr_no')"
                                :label="__('NCR No')"
                            />
                            <x-ui.sortable-th
                                column="title"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('title')"
                                :label="__('Title')"
                            />
                            <x-ui.sortable-th
                                column="ncr_kind"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('ncr_kind')"
                                :label="__('Kind')"
                            />
                            <x-ui.sortable-th
                                column="severity"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('severity')"
                                :label="__('Severity')"
                            />
                            <x-ui.sortable-th
                                column="status"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('status')"
                                :label="__('Status')"
                            />
                            <x-ui.sortable-th
                                column="reported_by_name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('reported_by_name')"
                                :label="__('Reporter')"
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
                        @forelse($ncrs as $ncr)
                            <tr wire:key="ncr-{{ $ncr->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $ncr->ncr_no }}</td>
                                <td class="px-table-cell-x py-table-cell-y">
                                    <a href="{{ route('quality.ncr.show', $ncr) }}" wire:navigate class="text-sm font-medium text-accent hover:underline">{{ $ncr->title }}</a>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ config('quality.ncr_kinds.' . $ncr->ncr_kind, $ncr->ncr_kind) }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    @if($ncr->severity)
                                        <x-ui.badge :variant="$this->severityVariant($ncr->severity)">{{ ucfirst($ncr->severity) }}</x-ui.badge>
                                    @else
                                        <span class="text-sm text-muted">—</span>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <x-ui.badge :variant="$this->statusVariant($ncr->status)">{{ str_replace('_', ' ', ucfirst($ncr->status)) }}</x-ui.badge>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $ncr->reported_by_name }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $ncr->created_at?->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No NCRs found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                {{ $ncrs->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
