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

            <x-ui.table container="flush" :caption="__('Authorization decision logs')">
                <x-slot name="head">
                        <tr>
                            <x-ui.sortable-th
                                column="occurred_at"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('occurred_at')"
                                :label="__('Occurred At')"
                            />
                            <x-ui.sortable-th
                                column="actor_name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('actor_name')"
                                :label="__('Actor')"
                            />
                            <x-ui.sortable-th
                                column="capability"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('capability')"
                                :label="__('Capability')"
                            />
                            <x-ui.sortable-th
                                column="allowed"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('allowed')"
                                :label="__('Result')"
                            />
                            <x-ui.sortable-th
                                column="reason_code"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('reason_code')"
                                :label="__('Reason')"
                            />
                            <x-ui.sortable-th
                                column="resource"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('resource')"
                                :label="__('Resource')"
                            />
                        </tr>
                </x-slot>

                        @forelse($logs as $log)
                            <tr wire:key="log-{{ $log->id }}">
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
            </x-ui.table>

            <div class="mt-2">
                {{ $logs->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
