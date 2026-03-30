<div>
    <x-slot name="title">{{ __('Decision Logs') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Decision Logs')" :subtitle="__('Authorization decision audit trail')" />

        <x-ui.card>
            <div class="mb-2 flex items-center gap-3">
                <div class="flex-1">
                    <x-ui.search-input
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search by capability, reason, actor, or resource...') }}"
                    />
                </div>
                <x-ui.select id="filter-result" wire:model.live="filterResult">
                    <option value="">{{ __('All Results') }}</option>
                    <option value="allowed">{{ __('Allowed') }}</option>
                    <option value="denied">{{ __('Denied') }}</option>
                </x-ui.select>
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Occurred At') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actor') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Capability') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Result') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Reason') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Resource') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($logs as $log)
                            <tr wire:key="log-{{ $log->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums"><x-ui.datetime :value="$log->occurred_at" /></td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    {{ $log->actor_name ?? $log->actor_type . '#' . $log->actor_id }}
                                    @if ($log->acting_for_user_id)
                                        <span class="text-xs text-muted">({{ __('as') }} #{{ $log->acting_for_user_id }})</span>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-sm text-ink">{{ $log->capability }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    @if ($log->allowed)
                                        <x-ui.badge variant="success">{{ __('Allowed') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="danger">{{ __('Denied') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $log->reason_code }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-xs text-muted">
                                    {{ $log->resource_type && $log->resource_id ? $log->resource_type . '#' . $log->resource_id : '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No decision logs found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                {{ $logs->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
