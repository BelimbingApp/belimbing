<div>
    <x-slot name="title">{{ __('Role Management') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Role Management')" :subtitle="__('Manage roles and their capabilities')">
            @if ($canCreate)
                <x-slot name="actions">
                    <x-ui.button variant="primary" as="a" href="{{ route('admin.roles.create') }}" wire:navigate>
                        <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                        {{ __('Create Role') }}
                    </x-ui.button>
                </x-slot>
            @endif
        </x-ui.page-header>

        <x-ui.session-flash />

        <x-ui.card>
            <div class="mb-2">
                <x-ui.search-input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by name, code, or description...') }}"
                />
            </div>

            <x-ui.table container="flush" :caption="__('Roles')">
                <x-slot name="head">
                        <tr>
                            <x-ui.sortable-th
                                column="name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('name')"
                                :label="__('Name')"
                            />
                            <x-ui.sortable-th
                                column="code"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('code')"
                                :label="__('Code')"
                            />
                            <x-ui.sortable-th
                                column="is_system"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('is_system')"
                                :label="__('Type')"
                            />
                            <x-ui.sortable-th
                                column="company_name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('company_name')"
                                :label="__('Scope')"
                            />
                            <x-ui.sortable-th
                                column="capabilities_count"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('capabilities_count')"
                                :label="__('Capabilities')"
                            />
                            <x-ui.sortable-th
                                column="principal_roles_count"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('principal_roles_count')"
                                :label="__('Assigned Users')"
                            />
                        </tr>
                </x-slot>

                        @forelse($roles as $role)
                            <tr wire:key="role-{{ $role->id }}">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <a href="{{ route('admin.roles.show', $role) }}" wire:navigate class="text-sm font-medium text-accent hover:underline">{{ $role->name }}</a>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $role->code }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    @if($role->is_system)
                                        <x-ui.badge variant="default">{{ __('System') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="success">{{ __('Custom') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $role->company?->name ?? __('Global') }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $role->capabilities_count }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $role->principal_roles_count }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No roles found.') }}</td>
                            </tr>
                        @endforelse
            </x-ui.table>

            <div class="mt-2">
                {{ $roles->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
