<x-layouts.app :title="__('Failed Jobs')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Failed Jobs')" :subtitle="__('Jobs that have failed processing')">
            <x-slot name="actions">
                <form method="POST" action="{{ route('admin.system.failed-jobs.retry') }}">
                    @csrf
                    <input type="hidden" name="id" value="all">
                    <x-ui.button type="submit">
                        <x-icon name="heroicon-o-arrow-path" class="w-4 h-4" />
                        {{ __('Retry All') }}
                    </x-ui.button>
                </form>
            </x-slot>
        </x-ui.page-header>

        <x-ui.card>
            <form method="GET" action="{{ route('admin.system.failed-jobs.index') }}" class="mb-2">
                <x-ui.search-input name="search" value="{{ $search }}" placeholder="{{ __('Search by queue, UUID, or exception...') }}" />
            </form>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('ID') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Queue') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Job Name') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Exception') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Failed At') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($failedJobs as $job)
                            <tr class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $job->id }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $job->queue }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink">{{ json_decode($job->payload)->displayName ?? __('Unknown') }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-muted max-w-xs truncate" title="{{ $job->exception }}">{{ Str::limit($job->exception, 100) }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $job->failed_at }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                    <form method="POST" action="{{ route('admin.system.failed-jobs.retry') }}" class="inline-block">
                                        @csrf
                                        <input type="hidden" name="id" value="{{ $job->uuid }}">
                                        <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 text-sm rounded-lg text-accent hover:bg-surface-subtle transition-colors">
                                            <x-icon name="heroicon-o-arrow-path" class="w-4 h-4" />
                                            {{ __('Retry') }}
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.system.failed-jobs.destroy', $job->id) }}" class="inline-block">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button type="submit" variant="danger-ghost" size="sm">
                                            <x-icon name="heroicon-o-trash" class="w-4 h-4" />
                                            {{ __('Delete') }}
                                        </x-ui.button>
                                    </form>
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
</x-layouts.app>
