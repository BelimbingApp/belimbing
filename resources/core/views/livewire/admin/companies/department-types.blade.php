<div>
    <x-slot name="title">{{ __('Department Types') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Department Types')" :subtitle="__('Manage department classification categories')">
            <x-slot name="actions">
                <a href="{{ route('admin.companies.index') }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl text-accent hover:bg-surface-subtle transition-colors">
                    <x-icon name="heroicon-o-arrow-left" class="w-5 h-5" />
                    {{ __('Back to Companies') }}
                </a>
                <x-ui.button variant="primary" wire:click="$set('showCreateModal', true)">
                    <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                    {{ __('Add Type') }}
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
                                column="code"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('code')"
                                :label="__('Code')"
                            />
                            <x-ui.sortable-th
                                column="name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('name')"
                                :label="__('Name')"
                            />
                            <x-ui.sortable-th
                                column="category"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('category')"
                                :label="__('Category')"
                            />
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Description') }}</th>
                            <x-ui.sortable-th
                                column="is_active"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('is_active')"
                                :label="__('Status')"
                            />
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($types as $type)
                            <tr wire:key="type-{{ $type->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted font-mono">{{ $type->code }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink"
                                    x-data="{ editing: false, val: '{{ addslashes($type->name) }}' }"
                                >
                                    <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                        <span x-text="val"></span>
                                        <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                                    </div>
                                    <input
                                        x-show="editing"
                                        x-ref="input"
                                        x-model="val"
                                        @keydown.enter="editing = false; $wire.saveField({{ $type->id }}, 'name', val)"
                                        @keydown.escape="editing = false; val = '{{ addslashes($type->name) }}'"
                                        @blur="editing = false; $wire.saveField({{ $type->id }}, 'name', val)"
                                        type="text"
                                        class="w-full px-1 -mx-1 py-0.5 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                                    />
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink"
                                    x-data="{ editing: false, val: '{{ addslashes($type->category) }}' }"
                                >
                                    <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.focus())" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                        <x-ui.badge variant="info"><span x-text="val" class="capitalize"></span></x-ui.badge>
                                        <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                                    </div>
                                    <select
                                        x-show="editing"
                                        x-ref="input"
                                        x-model="val"
                                        @change="editing = false; $wire.saveField({{ $type->id }}, 'category', val)"
                                        @blur="editing = false"
                                        class="w-full px-1 -mx-1 py-0.5 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                                    >
                                        <option value="administrative">{{ __('Administrative') }}</option>
                                        <option value="operational">{{ __('Operational') }}</option>
                                        <option value="revenue">{{ __('Revenue') }}</option>
                                        <option value="support">{{ __('Support') }}</option>
                                    </select>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-muted max-w-xs"
                                    x-data="{ editing: false, val: '{{ addslashes($type->description ?? '') }}' }"
                                >
                                    <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                        <span class="truncate" x-text="val || '-'"></span>
                                        <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity shrink-0" />
                                    </div>
                                    <input
                                        x-show="editing"
                                        x-ref="input"
                                        x-model="val"
                                        @keydown.enter="editing = false; $wire.saveField({{ $type->id }}, 'description', val)"
                                        @keydown.escape="editing = false; val = '{{ addslashes($type->description ?? '') }}'"
                                        @blur="editing = false; $wire.saveField({{ $type->id }}, 'description', val)"
                                        type="text"
                                        class="w-full px-1 -mx-1 py-0.5 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                                    />
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <button
                                        wire:click="toggleActive({{ $type->id }})"
                                        class="cursor-pointer"
                                        title="{{ __('Toggle active status') }}"
                                    >
                                        @if($type->is_active)
                                            <x-ui.badge variant="success">{{ __('Active') }}</x-ui.badge>
                                        @else
                                            <x-ui.badge variant="default">{{ __('Inactive') }}</x-ui.badge>
                                        @endif
                                    </button>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                    <x-ui.button
                                        variant="danger-ghost"
                                        size="sm"
                                        wire:click="deleteType({{ $type->id }})"
                                        wire:confirm="{{ __('Are you sure you want to delete this department type?') }}"
                                        :title="__('Delete department type')"
                                    >
                                        <x-icon name="heroicon-o-trash" class="w-4 h-4" />
                                        <span class="sr-only">{{ __('Delete') }}</span>
                                    </x-ui.button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No department types found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                {{ $types->links() }}
            </div>
        </x-ui.card>
    </div>

    <x-ui.modal wire:model="showCreateModal" class="max-w-lg">
        <form wire:submit="createType" class="p-6 space-y-6">
            <h2 class="text-lg font-medium tracking-tight text-ink">{{ __('Add Department Type') }}</h2>

            <div class="space-y-4">
                <x-ui.input
                    id="dept-type-code"
                    wire:model="createCode"
                    label="{{ __('Code') }}"
                    type="text"
                    required
                    placeholder="{{ __('e.g. engineering') }}"
                    :error="$errors->first('createCode')"
                />

                <x-ui.input
                    id="dept-type-name"
                    wire:model="createName"
                    label="{{ __('Name') }}"
                    type="text"
                    required
                    placeholder="{{ __('e.g. Engineering') }}"
                    :error="$errors->first('createName')"
                />

                <x-ui.select
                    id="dept-type-category"
                    wire:model="createCategory"
                    label="{{ __('Category') }}"
                    required
                    :error="$errors->first('createCategory')"
                >
                    <option value="administrative">{{ __('Administrative') }}</option>
                    <option value="operational">{{ __('Operational') }}</option>
                    <option value="revenue">{{ __('Revenue') }}</option>
                    <option value="support">{{ __('Support') }}</option>
                </x-ui.select>

                <x-ui.textarea
                    id="dept-type-description"
                    wire:model="createDescription"
                    label="{{ __('Description') }}"
                    rows="3"
                    placeholder="{{ __('Brief description of this department type') }}"
                    :error="$errors->first('createDescription')"
                />

                <x-ui.checkbox id="department-type-is-active" wire:model="createIsActive" label="{{ __('Active') }}" />
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
