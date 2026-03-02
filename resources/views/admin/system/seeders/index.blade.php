<x-layouts.app :title="__('Database Seeders')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Database Seeders')" :subtitle="__('Seeder registry status and execution history')" />

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <form method="GET" action="{{ route('admin.system.seeders.index') }}" class="mb-2">
                <x-ui.search-input name="search" value="{{ $search }}" placeholder="{{ __('Search by seeder class or module...') }}" />
            </form>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Seeder') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Module') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Ran At') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Error') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($seeders as $seeder)
                            <tr class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink font-mono" title="{{ $seeder->seeder_class }}">{{ class_basename($seeder->seeder_class) }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $seeder->module_name ?? '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    @php
                                        $variant = match ($seeder->status) {
                                            \App\Base\Database\Models\SeederRegistry::STATUS_COMPLETED => 'success',
                                            \App\Base\Database\Models\SeederRegistry::STATUS_FAILED => 'danger',
                                            \App\Base\Database\Models\SeederRegistry::STATUS_RUNNING => 'warning',
                                            default => 'default',
                                        };
                                    @endphp
                                    <x-ui.badge :variant="$variant">{{ __(ucfirst($seeder->status)) }}</x-ui.badge>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $seeder->ran_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-danger max-w-xs truncate" title="{{ $seeder->error_message }}">{{ $seeder->error_message ? Str::limit($seeder->error_message, 60) : '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                    <form method="POST" action="{{ route('admin.system.seeders.run') }}">
                                        @csrf
                                        <input type="hidden" name="seeder_class" value="{{ $seeder->seeder_class }}">
                                        <x-ui.button type="submit" size="sm">{{ __('Run') }}</x-ui.button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No seeders found.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">{{ $seeders->links() }}</div>
        </x-ui.card>
    </div>
</x-layouts.app>
