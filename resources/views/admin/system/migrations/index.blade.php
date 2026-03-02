<x-layouts.app :title="__('Migrations')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Migrations')" :subtitle="__('Database migration status and history')" />

        <x-ui.card>
            <div class="mb-2 flex items-center justify-between gap-4">
                <form method="GET" action="{{ route('admin.system.migrations.index') }}" class="flex-1">
                    <x-ui.search-input name="search" value="{{ $search }}" placeholder="{{ __('Search migrations...') }}" />
                </form>
                <div class="flex items-center gap-3 text-sm text-muted whitespace-nowrap">
                    <span>{{ __('Total:') }} <strong class="tabular-nums">{{ $totalCount }}</strong></span>
                    <span>{{ __('Latest Batch:') }} <strong class="tabular-nums">{{ $latestBatch ?? '—' }}</strong></span>
                </div>
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('ID') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Migration') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Batch') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($migrations as $migration)
                            <tr class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $migration->id }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink font-mono">{{ $migration->migration }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $migration->batch }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No migrations found.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">{{ $migrations->links() }}</div>
        </x-ui.card>
    </div>
</x-layouts.app>
