<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Base\Workflow\Livewire\Workflows\Index $this */
?>

<div>
    <x-slot name="title">{{ __('Workflows') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Workflows')" :subtitle="__('All registered workflow process definitions')" />

        <x-ui.card>
            <div class="mb-2">
                <x-ui.search-input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by code, label, or module...') }}"
                />
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <x-ui.sortable-th
                                column="label"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('label')"
                                :label="__('Label')"
                            />
                            <x-ui.sortable-th
                                column="code"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('code')"
                                :label="__('Code')"
                            />
                            <x-ui.sortable-th
                                column="module"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('module')"
                                :label="__('Module')"
                            />
                            <x-ui.sortable-th
                                column="status_configs_count"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('status_configs_count')"
                                :label="__('Statuses')"
                            />
                            <x-ui.sortable-th
                                column="transitions_count"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('transitions_count')"
                                :label="__('Transitions')"
                            />
                            <x-ui.sortable-th
                                column="kanban_columns_count"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('kanban_columns_count')"
                                :label="__('Kanban')"
                            />
                            <x-ui.sortable-th
                                column="is_active"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('is_active')"
                                :label="__('Active')"
                            />
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($workflows as $workflow)
                            <tr wire:key="wf-{{ $workflow->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <a href="{{ route('admin.workflows.show', $workflow) }}" wire:navigate class="text-sm font-medium text-accent hover:underline">{{ $workflow->label }}</a>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-sm text-muted">{{ $workflow->code }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $workflow->module ?? '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $workflow->status_configs_count }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $workflow->transitions_count }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $workflow->kanban_columns_count }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    @if($workflow->is_active)
                                        <x-ui.badge variant="success">{{ __('Active') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="default">{{ __('Inactive') }}</x-ui.badge>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No workflows found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                {{ $workflows->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
