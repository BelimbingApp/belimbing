<div>
    <x-slot name="title">{{ __('Employees') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Employees')" />

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <div class="mb-4">
                <x-ui.search-input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by name, employee number, email, or designation...') }}"
                />
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <x-ui.sortable-th
                                column="full_name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('full_name')"
                                :label="__('Employee')"
                            />
                            <x-ui.sortable-th
                                column="company_name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('company_name')"
                                :label="__('Company')"
                            />
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Department') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Designation') }}</th>
                            <x-ui.sortable-th
                                column="employee_type_label"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('employee_type_label')"
                                :label="__('Type')"
                            />
                            <x-ui.sortable-th
                                column="status"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('status')"
                                :label="__('Status')"
                            />
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($employees as $employee)
                            <tr wire:key="employee-{{ $employee->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <div class="text-sm font-medium text-default">{{ $employee->full_name }}</div>
                                    <div class="text-xs text-muted tabular-nums">{{ $employee->employee_number }}</div>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    {{ $employee->company?->name ?? '-' }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    {{ $employee->department?->type?->name ?? '-' }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    {{ $employee->designation ?? '-' }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <x-ui.badge variant="default">{{ $employee->employeeType?->label ?? ucfirst(str_replace('_', ' ', $employee->employee_type)) }}</x-ui.badge>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <x-ui.badge :variant="$this->statusVariant($employee->status)">{{ ucfirst($employee->status) }}</x-ui.badge>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No employees found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                {{ $employees->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
