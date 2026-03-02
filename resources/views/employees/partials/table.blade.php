<div class="-mx-card-inner overflow-x-auto px-card-inner" id="employees-list">
    <table class="min-w-full divide-y divide-border-default text-sm">
        <thead class="bg-surface-subtle/80">
            <tr>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Employee') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Company') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Department') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Designation') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Type') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</th>
                <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Actions') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-border-default bg-surface-card">
            @forelse ($employees as $employee)
                <tr class="transition-colors hover:bg-surface-subtle/50">
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y">
                        <a href="{{ route('admin.employees.show', $employee) }}" class="text-sm font-medium text-accent hover:underline">{{ $employee->full_name }}</a>
                        <div class="text-xs tabular-nums text-muted">{{ $employee->employee_number }}</div>
                    </td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $employee->company?->name ?? '-' }}</td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $employee->department?->type?->name ?? '-' }}</td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $employee->designation ?? '-' }}</td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y">
                        @if ($employee->isDigitalWorker())
                            <x-ui.badge variant="info">{{ $employee->employeeType?->label ?? ucfirst(str_replace('_', ' ', $employee->employee_type)) }}</x-ui.badge>
                        @else
                            <x-ui.badge>{{ $employee->employeeType?->label ?? ucfirst(str_replace('_', ' ', $employee->employee_type)) }}</x-ui.badge>
                        @endif
                    </td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y">
                        <x-ui.badge :variant="match($employee->status) {
                            'active' => 'success',
                            'terminated' => 'danger',
                            'probation' => 'warning',
                            default => 'default',
                        }">{{ ucfirst($employee->status) }}</x-ui.badge>
                    </td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-right">
                        <div class="flex items-center justify-end gap-2">
                            <form method="POST" action="{{ route('admin.employees.destroy', $employee) }}" onsubmit="return confirm('{{ __('Are you sure you want to delete this employee?') }}')">
                                @csrf
                                @method('DELETE')
                                <x-ui.button type="submit" variant="danger-ghost" size="sm">
                                    <x-icon name="heroicon-o-trash" class="h-4 w-4" />
                                    {{ __('Delete') }}
                                </x-ui.button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No employees found.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-2">
    {{ $employees->withQueryString()->links() }}
</div>
