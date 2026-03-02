<div class="-mx-card-inner overflow-x-auto px-card-inner">
    <table class="min-w-full divide-y divide-border-default text-sm">
        <thead class="bg-surface-subtle/80">
            <tr>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Principal') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Type') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Capability') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Access') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Company') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Granted At') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-border-default bg-surface-card">
            @forelse ($capabilities as $capability)
                <tr class="transition-colors hover:bg-surface-subtle/50">
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y">
                        <div class="text-sm font-medium text-ink">{{ $capability->principal_name ?? '#' . $capability->principal_id }}</div>
                        @if ($capability->principal_email)
                            <div class="text-xs text-muted">{{ $capability->principal_email }}</div>
                        @endif
                    </td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y">
                        @if ($capability->principal_type === 'human_user')
                            <x-ui.badge variant="default">{{ __('User') }}</x-ui.badge>
                        @else
                            <x-ui.badge variant="warning">{{ __('Digital Worker') }}</x-ui.badge>
                        @endif
                    </td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y font-mono text-sm text-ink">{{ $capability->capability_key }}</td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y">
                        @if ($capability->is_allowed)
                            <x-ui.badge variant="success">{{ __('Allow') }}</x-ui.badge>
                        @else
                            <x-ui.badge variant="danger">{{ __('Deny') }}</x-ui.badge>
                        @endif
                    </td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $capability->company_name ?? '—' }}</td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $capability->created_at->format('Y-m-d H:i') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No per-user overrides. Capabilities are currently granted through roles only.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-2">
    {{ $capabilities->withQueryString()->links() }}
</div>
