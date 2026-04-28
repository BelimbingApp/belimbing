<div>
    <x-slot name="title">{{ __('Employee Management') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Employee Management')">
            <x-slot name="actions">
                <x-ui.button
                    variant="primary"
                    as="a"
                    href="{{ route('admin.employees.create') }}"
                    wire:navigate
                >
                    <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                    {{ __('Create Employee') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <div class="mb-4 flex flex-col sm:flex-row gap-4">
                <div class="flex-1">
                    <x-ui.search-input
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search by name, employee number, email, designation, or job description...') }}"
                    />
                </div>
                <x-ui.select id="employee-type-filter" wire:model.live="typeFilter">
                    <option value="all">{{ __('All') }}</option>
                    <option value="human">{{ __('Human only') }}</option>
                    <option value="agent">{{ __('Agent only') }}</option>
                </x-ui.select>
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
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($employees as $employee)
                            <tr wire:key="employee-{{ $employee->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <a href="{{ route('admin.employees.show', $employee) }}" wire:navigate class="text-sm font-medium text-accent hover:underline">{{ $employee->full_name }}</a>
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
                                    @if($employee->isAgent())
                                        <x-ui.badge variant="info">{{ $this->employeeTypeLabel($employee) }}</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="default">{{ $this->employeeTypeLabel($employee) }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <x-ui.badge :variant="$this->statusVariant($employee->status)">{{ ucfirst($employee->status) }}</x-ui.badge>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <x-ui.button
                                            variant="danger-ghost"
                                            size="sm"
                                            wire:click="delete({{ $employee->id }})"
                                            wire:confirm="{{ __('Are you sure you want to delete this employee?') }}"
                                            :title="__('Delete employee')"
                                        >
                                            <x-icon name="heroicon-o-trash" class="w-4 h-4" />
                                            <span class="sr-only">{{ __('Delete') }}</span>
                                        </x-ui.button>
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
                {{ $employees->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
