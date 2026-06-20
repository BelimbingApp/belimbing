<div>
    <x-slot name="title">{{ __('Employee Types') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Employee Types')" :subtitle="__('Manage employee type reference data')">
            @if ($canCreate)
                <x-slot name="actions">
                    <x-ui.button variant="primary" as="a" href="{{ route('admin.employee-types.create') }}" wire:navigate>
                        <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                        {{ __('Add Type') }}
                    </x-ui.button>
                </x-slot>
            @endif
        </x-ui.page-header>

        <x-ui.session-flash />

        <x-ui.card>
            <div class="mb-2">
                <x-ui.search-input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by code or label...') }}"
                />
            </div>

            <x-ui.table container="flush" :caption="__('Employee types')" :row-hover="false">
                <x-slot name="head">
                <tr>
                    <x-ui.sortable-th
                        column="code"
                        :sort-by="$sortBy"
                        :sort-dir="$sortDir"
                        action="sort('code')"
                        :label="__('Code')"
                    />
                    <x-ui.sortable-th
                        column="label"
                        :sort-by="$sortBy"
                        :sort-dir="$sortDir"
                        action="sort('label')"
                        :label="__('Label')"
                    />
                    <x-ui.sortable-th
                        column="is_system"
                        :sort-by="$sortBy"
                        :sort-dir="$sortDir"
                        action="sort('is_system')"
                        :label="__('Kind')"
                    />
                    <x-ui.sortable-th
                        column="employees_count"
                        :sort-by="$sortBy"
                        :sort-dir="$sortDir"
                        action="sort('employees_count')"
                        :label="__('Employees')"
                    />
                    <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
                </tr>
                </x-slot>

                @forelse($employeeTypes as $type)
                    <tr wire:key="type-{{ $type->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-sm text-ink">{{ $type->code }}</td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink">{{ $type->label }}</td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                            @if($type->is_system)
                                <x-ui.badge variant="default">{{ __('System') }}</x-ui.badge>
                            @else
                                <x-ui.badge variant="success">{{ __('Custom') }}</x-ui.badge>
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $type->employees_count }}</td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                            @if(!$type->is_system)
                                <a href="{{ route('admin.employee-types.edit', $type) }}" wire:navigate title="{{ __('Edit employee type') }}" class="inline-flex items-center gap-1 px-3 py-1.5 text-sm rounded-lg text-accent hover:bg-surface-subtle transition-colors">
                                    <x-icon name="heroicon-o-pencil" class="w-4 h-4" />
                                    <span class="sr-only">{{ __('Edit') }}</span>
                                </a>
                                <x-ui.button
                                    variant="danger-ghost"
                                    size="sm"
                                    wire:click="delete({{ $type->id }})"
                                    wire:confirm="{{ __('Delete this employee type?') }}"
                                    :title="__('Delete employee type')"
                                >
                                    <x-icon name="heroicon-o-trash" class="w-4 h-4" />
                                    <span class="sr-only">{{ __('Delete') }}</span>
                                </x-ui.button>
                            @else
                                <span class="text-muted text-xs">{{ __('System types cannot be edited') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No employee types found.') }}</td>
                    </tr>
                @endforelse
            </x-ui.table>

            <div class="mt-2">
                {{ $employeeTypes->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
