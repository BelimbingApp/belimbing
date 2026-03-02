<x-layouts.app :title="__('Sessions')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Sessions')" :subtitle="__('View active sessions')" />

        <x-ui.card>
            <form method="GET" action="{{ route('admin.system.sessions.index') }}" class="mb-2">
                <x-ui.search-input name="search" value="{{ $search }}" placeholder="{{ __('Search by IP address or user agent...') }}" />
            </form>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('User') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('IP Address') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('User Agent') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Last Activity') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($sessions as $s)
                            <tr class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink">{{ $s->user_name ?? __('Guest') }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $s->ip_address ?? '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-muted max-w-xs truncate" title="{{ $s->user_agent }}">{{ Str::limit($s->user_agent, 80) }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ \Carbon\Carbon::createFromTimestamp($s->last_activity)->diffForHumans() }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    @if ($s->id === $currentSessionId)
                                        <x-ui.badge variant="success">{{ __('Current') }}</x-ui.badge>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No sessions found.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">{{ $sessions->links() }}</div>
        </x-ui.card>
    </div>
</x-layouts.app>
