<x-layouts.app :title="__('Jobs')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Jobs')" :subtitle="__('Queued jobs waiting to be processed')" />

        <x-ui.card>
            <form method="GET" action="{{ route('admin.system.jobs.index') }}" class="mb-2">
                <x-ui.search-input name="search" value="{{ $search }}" placeholder="{{ __('Search by queue or payload...') }}" />
            </form>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('ID') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Queue') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Job Name') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Attempts') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Available At') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Created At') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($jobs as $job)
                            <tr class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $job->id }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $job->queue }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink">{{ json_decode($job->payload)->displayName ?? __('Unknown') }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $job->attempts }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    @if($job->reserved_at)
                                        <x-ui.badge variant="warning">{{ __('Reserved') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="default">{{ __('Pending') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ \Carbon\Carbon::createFromTimestamp($job->available_at)->format('Y-m-d H:i:s') }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ \Carbon\Carbon::createFromTimestamp($job->created_at)->format('Y-m-d H:i:s') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No jobs found.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">{{ $jobs->links() }}</div>
        </x-ui.card>
    </div>
</x-layouts.app>
