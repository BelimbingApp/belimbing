<div>
    <x-slot name="title">{{ __('Relationships') }} — {{ $company->name }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Relationships') . ' — ' . $company->name">
            <x-slot name="actions">
                <a href="{{ route('admin.companies.show', $company) }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl text-accent hover:bg-surface-subtle transition-colors">
                    <x-icon name="heroicon-o-arrow-left" class="w-5 h-5" />
                    {{ __('Back to Company') }}
                </a>
                <x-ui.button variant="primary" wire:click="$set('showCreateModal', true)">
                    <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                    {{ __('Add Relationship') }}
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
                                column="other"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('other')"
                                :label="__('Related Company')"
                            />
                            <x-ui.sortable-th
                                column="type"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('type')"
                                :label="__('Type')"
                            />
                            <x-ui.sortable-th
                                column="direction"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('direction')"
                                :label="__('Direction')"
                            />
                            <x-ui.sortable-th
                                column="effective_from"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('effective_from')"
                                :label="__('Effective From')"
                            />
                            <x-ui.sortable-th
                                column="effective_to"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('effective_to')"
                                :label="__('Effective To')"
                            />
                            <x-ui.sortable-th
                                column="active"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('active')"
                                :label="__('Active?')"
                            />
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($allRelationships as $item)
                            <tr wire:key="rel-{{ $item->relationship->id }}-{{ $item->direction }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <a href="{{ route('admin.companies.show', $item->other) }}" wire:navigate class="text-sm font-medium text-accent hover:underline">{{ $item->other->name }}</a>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink">
                                    {{ $item->relationship->type->name }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <x-ui.badge :variant="$item->direction === 'outgoing' ? 'info' : 'default'">{{ ucfirst($item->direction) }}</x-ui.badge>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">
                                    <x-ui.datetime :value="$item->relationship->effective_from" format="date" />
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">
                                    <x-ui.datetime :value="$item->relationship->effective_to" format="date" />
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <x-ui.badge :variant="$item->relationship->isActive() ? 'success' : 'danger'">
                                        {{ $item->relationship->isActive() ? __('Yes') : __('No') }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            wire:click="editRelationship({{ $item->relationship->id }})"
                                            type="button"
                                            title="{{ __('Edit relationship dates') }}"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 text-sm rounded-lg text-accent hover:bg-surface-subtle transition-colors"
                                        >
                                            <x-icon name="heroicon-o-pencil" class="w-4 h-4" />
                                            <span class="sr-only">{{ __('Edit') }}</span>
                                        </button>
                                        <x-ui.button
                                            variant="danger-ghost"
                                            size="sm"
                                            wire:click="deleteRelationship({{ $item->relationship->id }})"
                                            wire:confirm="{{ __('Are you sure you want to delete this relationship?') }}"
                                            :title="__('Delete relationship')"
                                        >
                                            <x-icon name="heroicon-o-trash" class="w-4 h-4" />
                                            <span class="sr-only">{{ __('Delete') }}</span>
                                        </x-ui.button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No relationships found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>

    <x-ui.modal wire:model="showCreateModal" class="max-w-lg">
        <form wire:submit="createRelationship" class="space-y-6 p-6">
            <h3 class="text-xl font-medium tracking-tight text-ink">{{ __('Add Relationship') }}</h3>

            <x-ui.select id="relationship-related-company" wire:model="createRelatedCompanyId" :label="__('Related Company')">
                    <option value="0">{{ __('— Select Company —') }}</option>
                    @foreach($availableCompanies as $availableCompany)
                        <option value="{{ $availableCompany->id }}">{{ $availableCompany->name }}</option>
                    @endforeach
            </x-ui.select>

            <x-ui.select id="relationship-type" wire:model="createRelationshipTypeId" :label="__('Relationship Type')">
                    <option value="0">{{ __('— Select Type —') }}</option>
                    @foreach($relationshipTypes as $type)
                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                    @endforeach
            </x-ui.select>

            <x-ui.input
                id="rel-create-effective-from"
                wire:model="createEffectiveFrom"
                label="{{ __('Effective From') }}"
                type="date"
            />

            <x-ui.input
                id="rel-create-effective-to"
                wire:model="createEffectiveTo"
                label="{{ __('Effective To') }}"
                type="date"
            />

            <div class="flex justify-end gap-2">
                <x-ui.button wire:click="$set('showCreateModal', false)" variant="ghost">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="primary">
                    {{ __('Create') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal wire:model="showEditModal" class="max-w-lg">
        <form wire:submit="updateRelationship" class="space-y-6 p-6">
            <h3 class="text-xl font-medium tracking-tight text-ink">{{ __('Edit Relationship Dates') }}</h3>

            <x-ui.input
                id="rel-edit-effective-from"
                wire:model="editEffectiveFrom"
                label="{{ __('Effective From') }}"
                type="date"
            />

            <x-ui.input
                id="rel-edit-effective-to"
                wire:model="editEffectiveTo"
                label="{{ __('Effective To') }}"
                type="date"
            />

            <div class="flex justify-end gap-2">
                <x-ui.button wire:click="$set('showEditModal', false)" variant="ghost">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="primary">
                    {{ __('Update') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
