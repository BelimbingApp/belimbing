<div>
    <x-slot name="title">{{ __('Sessions') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Sessions')" :subtitle="__('View and manage active sessions')" />

        <x-ui.session-flash />

        <x-ui.card>
            <div class="mb-2">
                <x-ui.search-input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by IP address or user agent...') }}"
                />
            </div>

            <x-ui.table container="flush" :caption="__('Active sessions')">
                <x-slot name="head">
                        <tr>
                            <x-ui.sortable-th
                                column="user_name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('user_name')"
                                :label="__('User')"
                            />
                            <x-ui.sortable-th
                                column="ip_address"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('ip_address')"
                                :label="__('IP Address')"
                            />
                            <x-ui.sortable-th
                                column="user_agent"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('user_agent')"
                                :label="__('User Agent')"
                            />
                            <x-ui.sortable-th
                                column="last_activity"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('last_activity')"
                                :label="__('Last Activity')"
                            />
                            <x-ui.th>{{ __('Status') }}</x-ui.th>
                            <x-ui.th>{{ __('Actions') }}</x-ui.th>
                        </tr>
                </x-slot>

                        @forelse($sessions as $s)
                            <tr wire:key="session-{{ $s->id }}">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink">{{ $s->user_name ?? __('Guest') }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $s->ip_address ?? '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-muted max-w-xs truncate" title="{{ $s->user_agent }}">{{ Str::limit($s->user_agent, 80) }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{\Carbon\Carbon::createFromTimestamp($s->last_activity)->diffForHumans() }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    @if ($s->id === $currentSessionId)
                                        <x-ui.badge variant="success">{{ __('Current') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    @if ($s->id !== $currentSessionId)
                                        <x-ui.button
                                            variant="danger"
                                            size="sm"
                                            wire:click="terminate('{{ $s->id }}')"
                                            wire:confirm="{{ __('Are you sure you want to terminate this session?') }}"
                                            :title="__('Terminate session')"
                                        >
                                            <x-icon name="heroicon-o-x-circle" class="w-4 h-4" />
                                            <span class="sr-only">{{ __('Terminate') }}</span>
                                        </x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No sessions found.') }}</td>
                            </tr>
                        @endforelse
            </x-ui.table>

            <div class="mt-2">
                {{ $sessions->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
