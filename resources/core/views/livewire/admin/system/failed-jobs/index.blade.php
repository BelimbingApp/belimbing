<div>
    <x-slot name="title">{{ __('Failed Jobs') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Failed Jobs')" :subtitle="__('Jobs that have failed processing')">
            <x-slot name="actions">
                <x-ui.button
                    wire:click="retryAll"
                    wire:confirm="{{ __('Are you sure you want to retry all failed jobs?') }}"
                >
                    <x-icon name="heroicon-o-arrow-path" class="w-4 h-4" />
                    {{ __('Retry All') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        <x-ui.card>
            <div class="mb-2">
                <x-ui.search-input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by queue, UUID, or exception...') }}"
                />
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <x-ui.sortable-th
                                column="id"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('id')"
                                :label="__('ID')"
                            />
                            <x-ui.sortable-th
                                column="queue"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('queue')"
                                :label="__('Queue')"
                            />
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Job Name') }}</th>
                            <x-ui.sortable-th
                                column="exception"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('exception')"
                                :label="__('Exception')"
                            />
                            <x-ui.sortable-th
                                column="failed_at"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('failed_at')"
                                :label="__('Failed At')"
                            />
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($failedJobs as $job)
                            <tr wire:key="failed-job-{{ $job->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $job->id }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $job->queue }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink">{{ json_decode($job->payload)->displayName ?? __('Unknown') }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-muted max-w-xs truncate" title="{{ $job->exception }}">{{ Str::limit($job->exception, 100) }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $job->failed_at }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                    <button
                                        type="button"
                                        wire:click="retryJob('{{ $job->uuid }}')"
                                        title="{{ __('Retry failed job') }}"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-sm rounded-lg text-accent hover:bg-surface-subtle transition-colors"
                                    >
                                        <x-icon name="heroicon-o-arrow-path" class="w-4 h-4" />
                                        <span class="sr-only">{{ __('Retry') }}</span>
                                    </button>
                                    <x-ui.button
                                        variant="danger-ghost"
                                        size="sm"
                                        wire:click="deleteJob({{ $job->id }})"
                                        wire:confirm="{{ __('Are you sure you want to delete this failed job?') }}"
                                        :title="__('Delete failed job')"
                                    >
                                        <x-icon name="heroicon-o-trash" class="w-4 h-4" />
                                        <span class="sr-only">{{ __('Delete') }}</span>
                                    </x-ui.button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No failed jobs found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                {{ $failedJobs->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
