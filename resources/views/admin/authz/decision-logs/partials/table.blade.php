<div class="-mx-card-inner overflow-x-auto px-card-inner">
    <table class="min-w-full divide-y divide-border-default text-sm">
        <thead class="bg-surface-subtle/80">
            <tr>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Occurred At') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Actor') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Capability') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Result') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Reason') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Resource') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-border-default bg-surface-card">
            @forelse ($logs as $log)
                <tr class="transition-colors hover:bg-surface-subtle/50">
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm tabular-nums text-muted">{{ $log->occurred_at->format('Y-m-d H:i:s') }}</td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">
                        {{ $log->actor_name ?? $log->actor_type . '#' . $log->actor_id }}
                        @if ($log->acting_for_user_id)
                            <span class="text-xs text-muted">({{ __('as') }} #{{ $log->acting_for_user_id }})</span>
                        @endif
                    </td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y font-mono text-sm text-ink">{{ $log->capability }}</td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y">
                        @if ($log->allowed)
                            <x-ui.badge variant="success">{{ __('Allowed') }}</x-ui.badge>
                        @else
                            <x-ui.badge variant="danger">{{ __('Denied') }}</x-ui.badge>
                        @endif
                    </td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $log->reason_code }}</td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-xs text-muted">{{ $log->resource_type && $log->resource_id ? $log->resource_type . '#' . $log->resource_id : '—' }}</td>
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
    {{ $logs->withQueryString()->links() }}
</div>
