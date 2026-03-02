<x-layouts.app :title="__('Job Batches')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Job Batches')" :subtitle="__('Batched job groups and their progress')" />

        <x-ui.card>
            <form method="GET" action="{{ route('admin.system.job-batches.index') }}" class="mb-2">
                <x-ui.search-input name="search" value="{{ $search }}" placeholder="{{ __('Search by batch name or ID...') }}" />
            </form>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Name') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Progress') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Failed') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Created At') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($batches as $batch)
                            @php
                                $completed = $batch->total_jobs - $batch->pending_jobs - $batch->failed_jobs;
                                $percentage = $batch->total_jobs > 0 ? round(($completed / $batch->total_jobs) * 100) : 0;
                            @endphp
                            <tr class="hover:bg-surface-subtle/50 transition-colors">
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
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ \Carbon\Carbon::createFromTimestamp($batch->created_at)->format('Y-m-d H:i:s') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No job batches found.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">{{ $batches->links() }}</div>
        </x-ui.card>
    </div>
</x-layouts.app>
