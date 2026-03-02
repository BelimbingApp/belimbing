<x-layouts.app :title="__('AI Providers')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('AI Providers')">
            <x-slot name="actions">
                <x-ui.button as="a" href="{{ route('admin.ai.providers.create') }}">
                    <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                    {{ __('Add Provider') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))<x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>@endif

        <x-ui.card>
            <form method="GET" action="{{ route('admin.ai.providers.index') }}" class="mb-2">
                <x-ui.search-input name="search" value="{{ $search }}" placeholder="{{ __('Search providers...') }}" />
            </form>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Name') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Base URL') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Models') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($providers as $provider)
                            <tr class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-accent">
                                    <a href="{{ route('admin.ai.providers.show', $provider) }}">{{ $provider->display_name }}</a>
                                    <div class="text-xs text-muted font-mono">{{ $provider->name }}</div>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $provider->base_url }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $provider->models_count }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <x-ui.badge :variant="$provider->is_active ? 'success' : 'default'">{{ $provider->is_active ? __('Active') : __('Inactive') }}</x-ui.badge>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No providers found.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">{{ $providers->links() }}</div>
        </x-ui.card>
    </div>
</x-layouts.app>
