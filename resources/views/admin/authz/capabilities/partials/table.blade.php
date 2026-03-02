<div class="-mx-card-inner overflow-x-auto px-card-inner">
    <table class="min-w-full divide-y divide-border-default text-sm">
        <thead class="bg-surface-subtle/80">
            <tr>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Capability') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Domain') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Resource') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Action') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Module') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-border-default bg-surface-card">
            @forelse ($capabilities as $capability)
                <tr class="transition-colors hover:bg-surface-subtle/50">
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y font-mono text-sm text-ink">{{ $capability->key }}</td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $capability->domain }}</td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $capability->resource }}</td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $capability->action }}</td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $capability->module }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No capabilities found.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
