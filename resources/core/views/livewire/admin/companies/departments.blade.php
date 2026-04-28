<div>
    <x-slot name="title">{{ __('Departments') }} — {{ $company->name }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Departments') . ' — ' . $company->name">
            <x-slot name="actions">
                <a href="{{ route('admin.companies.show', $company) }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl text-accent hover:bg-surface-subtle transition-colors">
                    <x-icon name="heroicon-o-arrow-left" class="w-5 h-5" />
                    {{ __('Back to Company') }}
                </a>
                <x-ui.button variant="primary" wire:click="$set('showCreateModal', true)">
                    <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                    {{ __('Add Department') }}
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
            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <x-ui.sortable-th
                                column="type_name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('type_name')"
                                :label="__('Department Type')"
                            />
                            <x-ui.sortable-th
                                column="category"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('category')"
                                :label="__('Category')"
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
                        @forelse($departments as $department)
                            <tr wire:key="department-{{ $department->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink">
                                    @if($department->type?->code)
                                        <span class="font-mono text-xs text-muted">{{ $department->type->code }}</span>
                                        <span class="ml-1.5">{{ $department->type->name }}</span>
                                    @else
                                        {{ $department->type?->name ?? '-' }}
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    {{ $department->type?->category ?? '-' }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap"
                                    x-data="{ editing: false, val: '{{ $department->status }}' }"
                                >
                                    <div x-show="!editing" @click="editing = true" class="group flex items-center gap-1.5 cursor-pointer">
                                        <x-ui.badge :variant="match($department->status) { 'active' => 'success', 'suspended' => 'danger', default => 'default' }">
                                            {{ ucfirst($department->status) }}
                                        </x-ui.badge>
                                        <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                                    </div>
                                    <select
                                        x-show="editing"
                                        x-model="val"
                                        @change="editing = false; $wire.saveStatus({{ $department->id }}, val)"
                                        @keydown.escape="editing = false; val = '{{ $department->status }}'"
                                        @blur="editing = false"
                                        class="px-2 py-1 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                                    >
                                        <option value="active">{{ __('Active') }}</option>
                                        <option value="inactive">{{ __('Inactive') }}</option>
                                        <option value="suspended">{{ __('Suspended') }}</option>
                                    </select>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <x-ui.button
                                            variant="danger-ghost"
                                            size="sm"
                                            wire:click="deleteDepartment({{ $department->id }})"
                                            wire:confirm="{{ __('Are you sure you want to delete this department?') }}"
                                            :title="__('Delete department')"
                                        >
                                            <x-icon name="heroicon-o-trash" class="w-4 h-4" />
                                            <span class="sr-only">{{ __('Delete') }}</span>
                                        </x-ui.button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No departments found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                {{ $departments->links() }}
            </div>
        </x-ui.card>
    </div>

    <x-ui.modal wire:model="showCreateModal" class="max-w-lg">
        <form wire:submit="createDepartment" class="p-6 space-y-6">
            <h2 class="text-lg font-medium tracking-tight text-ink">{{ __('Add Department') }}</h2>

                <div class="space-y-4">
                    <x-ui.select id="department-type" wire:model="createDepartmentTypeId" :label="__('Department Type')">
                        <option value="0">{{ __('Select a department type...') }}</option>
                        @foreach($availableTypes as $type)
                            <option value="{{ $type->id }}">{{ $type->code ? $type->code . ' — ' : '' }}{{ $type->name }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.select id="department-status" wire:model="createStatus" :label="__('Status')">
                        <option value="active">{{ __('Active') }}</option>
                        <option value="inactive">{{ __('Inactive') }}</option>
                        <option value="suspended">{{ __('Suspended') }}</option>
                    </x-ui.select>
                </div>

            <div class="flex items-center gap-4">
                <x-ui.button type="submit" variant="primary">
                    {{ __('Create') }}
                </x-ui.button>
                <button type="button" wire:click="$set('showCreateModal', false)" class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl text-accent hover:bg-surface-subtle transition-colors">
                    {{ __('Cancel') }}
                </button>
            </div>
        </form>
    </x-ui.modal>

</div>
