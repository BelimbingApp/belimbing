<x-layouts.app :title="__('Employee Types')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Employee Types')" :subtitle="__('Manage employee type reference data')">
            @if ($canCreate)
                <x-slot name="actions">
                    <x-ui.button variant="primary" as="a" href="{{ route('admin.employee-types.create') }}">
                        <x-icon name="heroicon-o-plus" class="h-4 w-4" />
                        {{ __('Add Type') }}
                    </x-ui.button>
                </x-slot>
            @endif
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <div class="-mx-card-inner overflow-x-auto px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Code') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Label') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Kind') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Employees') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-default bg-surface-card">
                        @forelse ($employeeTypes as $employeeType)
                            <tr class="transition-colors hover:bg-surface-subtle/50">
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y font-mono text-sm text-ink">{{ $employeeType->code }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-ink">{{ $employeeType->label }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y">
                                    @if ($employeeType->is_system)
                                        <x-ui.badge>{{ __('System') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="success">{{ __('Custom') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm tabular-nums text-muted">{{ $employeeType->employees_count }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-right">
                                    @if (! $employeeType->is_system)
                                        <div class="flex items-center justify-end gap-2">
                                            @if ($canUpdate)
                                                <a href="{{ route('admin.employee-types.edit', $employeeType) }}" class="inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm text-accent transition-colors hover:bg-surface-subtle">
                                                    <x-icon name="heroicon-o-pencil" class="h-4 w-4" />
                                                    {{ __('Edit') }}
                                                </a>
                                            @endif

                                            @if ($canDelete)
                                                <form method="POST" action="{{ route('admin.employee-types.destroy', $employeeType) }}" onsubmit="return confirm('{{ __('Delete this employee type?') }}')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <x-ui.button type="submit" variant="danger-ghost" size="sm">
                                                        <x-icon name="heroicon-o-trash" class="h-4 w-4" />
                                                        {{ __('Delete') }}
                                                    </x-ui.button>
                                                </form>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-xs text-muted">{{ __('System types cannot be edited') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No employee types found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">{{ $employeeTypes->links() }}</div>
        </x-ui.card>
    </div>
</x-layouts.app>
