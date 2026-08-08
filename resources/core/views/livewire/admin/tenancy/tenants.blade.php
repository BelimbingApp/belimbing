<div>
    <x-slot name="title">{{ __('Tenants') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Tenants')" :subtitle="__('Each tenant is an isolated customer of this instance')">
            <x-slot name="actions">
                <x-ui.button variant="primary" wire:click="$set('showCreateModal', true)">
                    <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                    {{ __('Add Tenant') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        <x-ui.session-flash />

        <x-ui.card>
            <x-ui.table container="flush" :caption="__('Tenants')" :row-hover="false">
                <x-slot name="head">
                <tr>
                    <x-ui.sortable-th
                        column="id"
                        :sort-by="$sortBy"
                        :sort-dir="$sortDir"
                        action="sort('id')"
                        :label="__('ID')"
                    />
                    <x-ui.sortable-th
                        column="name"
                        :sort-by="$sortBy"
                        :sort-dir="$sortDir"
                        action="sort('name')"
                        :label="__('Name')"
                    />
                    <x-ui.th>{{ __('Parent') }}</x-ui.th>
                    <x-ui.th>{{ __('Sub-tenants') }}</x-ui.th>
                    <x-ui.sortable-th
                        column="status"
                        :sort-by="$sortBy"
                        :sort-dir="$sortDir"
                        action="sort('status')"
                        :label="__('Status')"
                    />
                </tr>
                </x-slot>

                @forelse($tenants as $tenant)
                    <tr wire:key="tenant-{{ $tenant->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $tenant->id }}</td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink">
                            {{ $tenant->name }}
                            @if($tenant->isLicensee())
                                <x-ui.badge variant="info">{{ __('Licensee') }}</x-ui.badge>
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $tenant->parent?->name ?? '—' }}</td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $tenant->children_count }}</td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                            @if($tenant->status === 'active')
                                <x-ui.badge variant="success">{{ __('Active') }}</x-ui.badge>
                            @else
                                <x-ui.badge variant="default">{{ ucfirst($tenant->status) }}</x-ui.badge>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No tenants found.') }}</td>
                    </tr>
                @endforelse
            </x-ui.table>

            <div class="mt-2">
                {{ $tenants->links() }}
            </div>
        </x-ui.card>
    </div>

    <x-ui.modal wire:model="showCreateModal" class="max-w-lg">
        <form wire:submit="createTenant" class="p-6 space-y-6">
            <h2 class="text-lg font-medium tracking-tight text-ink">{{ __('Add Tenant') }}</h2>

            <div class="space-y-4">
                <x-ui.input
                    id="tenant-name"
                    wire:model="createName"
                    label="{{ __('Name') }}"
                    type="text"
                    required
                    placeholder="{{ __('e.g. Acme Sdn Bhd') }}"
                    :error="$errors->first('createName')"
                />

                <x-ui.select
                    id="tenant-parent"
                    wire:model="createParentId"
                    label="{{ __('Parent tenant') }}"
                    :error="$errors->first('createParentId')"
                >
                    <option value="">{{ __('None (top-level tenant)') }}</option>
                    @foreach($parentOptions as $parent)
                        <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.select
                    id="tenant-status"
                    wire:model="createStatus"
                    label="{{ __('Status') }}"
                    required
                    :error="$errors->first('createStatus')"
                >
                    <option value="active">{{ __('Active') }}</option>
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
