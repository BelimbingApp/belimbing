<div class="-mx-card-inner overflow-x-auto px-card-inner">
    <table class="min-w-full divide-y divide-border-default text-sm">
        <thead class="bg-surface-subtle/80">
            <tr>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Name') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Code') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Type') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Scope') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Capabilities') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Assigned Users') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-border-default bg-surface-card">
            @forelse ($roles as $role)
                <tr class="transition-colors hover:bg-surface-subtle/50">
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y">
                        <a href="{{ route('admin.roles.show', $role) }}" class="text-sm font-medium text-accent hover:underline">{{ $role->name }}</a>
                    </td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $role->code }}</td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y">
                        @if ($role->is_system)
                            <x-ui.badge variant="default">{{ __('System') }}</x-ui.badge>
                        @else
                            <x-ui.badge variant="success">{{ __('Custom') }}</x-ui.badge>
                        @endif
                    </td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $role->company?->name ?? __('Global') }}</td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm tabular-nums text-muted">{{ $role->capabilities_count }}</td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm tabular-nums text-muted">{{ $role->principal_roles_count }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No roles found.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-2">
    {{ $roles->withQueryString()->links() }}
</div>
