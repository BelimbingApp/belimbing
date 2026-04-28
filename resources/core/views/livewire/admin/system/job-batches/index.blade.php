<div>
    <x-slot name="title">{{ __('Job Batches') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Job Batches')" :subtitle="__('Batched job groups and their progress')">
            <x-slot name="actions">
                <x-ui.button
                    wire:click="pruneCompleted"
                    wire:confirm="{{ __('Are you sure you want to prune all completed batches?') }}"
                >
                    <x-icon name="heroicon-o-trash" class="w-4 h-4" />
                    {{ __('Prune Completed') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        <x-ui.card>
            <div class="mb-2">
                <x-ui.search-input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by batch name or ID...') }}"
                />
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <x-ui.sortable-th
                                column="name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('name')"
                                :label="__('Name')"
                            />
                            <x-ui.sortable-th
                                column="total_jobs"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('total_jobs')"
                                :label="__('Progress')"
                            />
                            <x-ui.sortable-th
                                column="failed_jobs"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('failed_jobs')"
                                :label="__('Failed')"
                            />
                            <x-ui.sortable-th
                                column="cancelled_at"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('cancelled_at')"
                                :label="__('Status')"
                            />
                            <x-ui.sortable-th
                                column="created_at"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('created_at')"
                                :label="__('Created At')"
                            />
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($batches as $batch)
                            @php
                                $completed = $batch->total_jobs - $batch->pending_jobs - $batch->failed_jobs;
                                $percentage = $batch->total_jobs > 0 ? round(($completed / $batch->total_jobs) * 100) : 0;
                            @endphp
                            <tr wire:key="batch-{{ $batch->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink">{{ $batch->name }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $completed }}/{{ $batch->total_jobs }} ({{ $percentage }}%)</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $batch->failed_jobs }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    @if($batch->cancelled_at)
                                        <x-ui.badge variant="danger">{{ __('Cancelled') }}</x-ui.badge>
                                    @elseif($batch->finished_at)
                                        <x-ui.badge variant="success">{{ __('Finished') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="warning">{{ __('In Progress') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{\Carbon\Carbon::createFromTimestamp($batch->created_at)->format('Y-m-d H:i:s') }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                    @if(!$batch->cancelled_at && !$batch->finished_at)
                                        <x-ui.button
                                            variant="danger-ghost"
                                            size="sm"
                                            wire:click="cancelBatch('{{ $batch->id }}')"
                                            wire:confirm="{{ __('Are you sure you want to cancel this batch?') }}"
                                            :title="__('Cancel batch')"
                                        >
                                            <x-icon name="heroicon-o-x-circle" class="w-4 h-4" />
                                            <span class="sr-only">{{ __('Cancel') }}</span>
                                        </x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No job batches found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                {{ $batches->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
