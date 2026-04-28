<div
    x-data="{
        init() {
            this._onNavigated = () => this.$wire.$refresh();
            document.addEventListener('livewire:navigated', this._onNavigated);
        },
        destroy() {
            document.removeEventListener('livewire:navigated', this._onNavigated);
        },
    }"
>
    <x-slot name="title">{{ $company->name }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$company->name" :subtitle="$company->legal_name" :pinnable="['label' => __('Administration') . '/' . __('Companies') . '/' . $company->name, 'url' => route('admin.companies.show', $company)]">
            <x-slot name="actions">
                <a href="{{ route('admin.companies.index') }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl text-accent hover:bg-surface-subtle transition-colors">
                    <x-icon name="heroicon-o-arrow-left" class="w-5 h-5" />
                    {{ __('Back to List') }}
                </a>
            </x-slot>
        </x-ui.page-header>

        @if ($company->isLicensee())
            <x-ui.alert variant="info">{{ __('This is the licensee company operating this Belimbing instance.') }}</x-ui.alert>
        @endif

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @include('livewire.admin.companies.partials.company-details')

        @include('livewire.admin.companies.partials.company-addresses')

        @if($company->children->isNotEmpty())
            <x-ui.card>
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">
                    {{ __('Subsidiaries') }}
                    <x-ui.badge>{{ $company->children->count() }}</x-ui.badge>
                </h3>

                <div class="overflow-x-auto -mx-card-inner px-card-inner">
                    <table class="min-w-full divide-y divide-border-default text-sm">
                        <thead class="bg-surface-subtle/80">
                            <tr>
                                <x-ui.sortable-th
                                    column="name"
                                    :sort-by="$childrenSortBy"
                                    :sort-dir="$childrenSortDir"
                                    action="sortChildren('name')"
                                    :label="__('Name')"
                                />
                                <x-ui.sortable-th
                                    column="status"
                                    :sort-by="$childrenSortBy"
                                    :sort-dir="$childrenSortDir"
                                    action="sortChildren('status')"
                                    :label="__('Status')"
                                />
                                <x-ui.sortable-th
                                    column="legal_entity_type"
                                    :sort-by="$childrenSortBy"
                                    :sort-dir="$childrenSortDir"
                                    action="sortChildren('legal_entity_type')"
                                    :label="__('Legal Entity Type')"
                                />
                                <x-ui.sortable-th
                                    column="jurisdiction"
                                    :sort-by="$childrenSortBy"
                                    :sort-dir="$childrenSortDir"
                                    action="sortChildren('jurisdiction')"
                                    :label="__('Jurisdiction')"
                                />
                            </tr>
                        </thead>
                        <tbody class="bg-surface-card divide-y divide-border-default">
                            @foreach($sortedChildren as $child)
                                <tr wire:key="child-{{ $child->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink font-medium">
                                        <a href="{{ route('admin.companies.show', $child) }}" wire:navigate class="text-accent hover:underline">{{ $child->name }}</a>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                        <x-ui.badge :variant="match($child->status) {
                                            'active' => 'success',
                                            'suspended' => 'danger',
                                            'pending' => 'warning',
                                            default => 'default',
                                        }">{{ ucfirst($child->status) }}</x-ui.badge>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $child->legalEntityType?->name ?? '-' }}</td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $child->jurisdiction ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif

        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">
                    {{ __('Departments') }}
                    <x-ui.badge>{{ $company->departments->count() }}</x-ui.badge>
                </h3>
                <x-ui.button variant="ghost" size="sm" as="a" href="{{ route('admin.companies.departments', $company) }}" wire:navigate>
                    <x-icon name="heroicon-o-cog-6-tooth" class="w-4 h-4" />
                    {{ __('Manage') }}
                </x-ui.button>
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <x-ui.sortable-th
                                column="department_type"
                                :sort-by="$departmentsSortBy"
                                :sort-dir="$departmentsSortDir"
                                action="sortDepartments('department_type')"
                                :label="__('Department Type')"
                            />
                            <x-ui.sortable-th
                                column="category"
                                :sort-by="$departmentsSortBy"
                                :sort-dir="$departmentsSortDir"
                                action="sortDepartments('category')"
                                :label="__('Category')"
                            />
                            <x-ui.sortable-th
                                column="status"
                                :sort-by="$departmentsSortBy"
                                :sort-dir="$departmentsSortDir"
                                action="sortDepartments('status')"
                                :label="__('Status')"
                            />
                            <x-ui.sortable-th
                                column="head"
                                :sort-by="$departmentsSortBy"
                                :sort-dir="$departmentsSortDir"
                                action="sortDepartments('head')"
                                :label="__('Head')"
                            />
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($sortedDepartments as $dept)
                            <tr wire:key="dept-{{ $dept->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink font-medium">{{ $dept->type->name }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $dept->type->category ?? '-' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <x-ui.badge :variant="match($dept->status) {
                                        'active' => 'success',
                                        'suspended' => 'danger',
                                        'pending' => 'warning',
                                        default => 'default',
                                    }">{{ ucfirst($dept->status) }}</x-ui.badge>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $dept->head?->displayName() ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No departments.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">
                    {{ __('Relationships') }}
                    <x-ui.badge>{{ $sortedRelationships->count() }}</x-ui.badge>
                </h3>
                <x-ui.button variant="ghost" size="sm" as="a" href="{{ route('admin.companies.relationships', $company) }}" wire:navigate>
                    <x-icon name="heroicon-o-cog-6-tooth" class="w-4 h-4" />
                    {{ __('Manage') }}
                </x-ui.button>
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <x-ui.sortable-th
                                column="company_name"
                                :sort-by="$relationshipsSortBy"
                                :sort-dir="$relationshipsSortDir"
                                action="sortRelationships('company_name')"
                                :label="__('Related Company')"
                            />
                            <x-ui.sortable-th
                                column="relationship_type"
                                :sort-by="$relationshipsSortBy"
                                :sort-dir="$relationshipsSortDir"
                                action="sortRelationships('relationship_type')"
                                :label="__('Type')"
                            />
                            <x-ui.sortable-th
                                column="direction"
                                :sort-by="$relationshipsSortBy"
                                :sort-dir="$relationshipsSortDir"
                                action="sortRelationships('direction')"
                                :label="__('Direction')"
                            />
                            <x-ui.sortable-th
                                column="effective_from"
                                :sort-by="$relationshipsSortBy"
                                :sort-dir="$relationshipsSortDir"
                                action="sortRelationships('effective_from')"
                                :label="__('Effective From')"
                            />
                            <x-ui.sortable-th
                                column="effective_to"
                                :sort-by="$relationshipsSortBy"
                                :sort-dir="$relationshipsSortDir"
                                action="sortRelationships('effective_to')"
                                :label="__('Effective To')"
                            />
                            <x-ui.sortable-th
                                column="is_active"
                                :sort-by="$relationshipsSortBy"
                                :sort-dir="$relationshipsSortDir"
                                action="sortRelationships('is_active')"
                                :label="__('Status')"
                            />
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($sortedRelationships as $rel)
                            <tr wire:key="rel-{{ $rel->id }}-{{ $rel->direction }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink font-medium">
                                    <a href="{{ route('admin.companies.show', $rel->company) }}" wire:navigate class="text-accent hover:underline">{{ $rel->company->name }}</a>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $rel->type?->name ?? '-' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $rel->direction }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums"><x-ui.datetime :value="$rel->effective_from" format="date" /></td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums"><x-ui.datetime :value="$rel->effective_to" format="date" /></td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <x-ui.badge :variant="$rel->is_active ? 'success' : 'default'">{{ $rel->is_active ? __('Active') : __('Ended') }}</x-ui.badge>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No relationships.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        <x-ui.card>
            <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">
                {{ __('External Accesses') }}
                <x-ui.badge>{{ $sortedExternalAccesses->count() }}</x-ui.badge>
            </h3>
            <p class="text-xs text-muted mt-0.5 mb-4">{{ __('Portal access granted by this company to external users. Allows customers or suppliers to view shared data.') }}</p>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <x-ui.sortable-th
                                column="user"
                                :sort-by="$externalAccessesSortBy"
                                :sort-dir="$externalAccessesSortDir"
                                action="sortExternalAccesses('user')"
                                :label="__('User')"
                            />
                            <x-ui.sortable-th
                                column="permissions"
                                :sort-by="$externalAccessesSortBy"
                                :sort-dir="$externalAccessesSortDir"
                                action="sortExternalAccesses('permissions')"
                                :label="__('Permissions')"
                            />
                            <x-ui.sortable-th
                                column="access_status"
                                :sort-by="$externalAccessesSortBy"
                                :sort-dir="$externalAccessesSortDir"
                                action="sortExternalAccesses('access_status')"
                                :label="__('Status')"
                            />
                            <x-ui.sortable-th
                                column="granted_at"
                                :sort-by="$externalAccessesSortBy"
                                :sort-dir="$externalAccessesSortDir"
                                action="sortExternalAccesses('granted_at')"
                                :label="__('Granted At')"
                            />
                            <x-ui.sortable-th
                                column="expires_at"
                                :sort-by="$externalAccessesSortBy"
                                :sort-dir="$externalAccessesSortDir"
                                action="sortExternalAccesses('expires_at')"
                                :label="__('Expires At')"
                            />
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($sortedExternalAccesses as $access)
                            <tr wire:key="access-{{ $access->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    @if($access->user)
                                        <a href="{{ route('admin.users.show', $access->user) }}" wire:navigate class="text-accent hover:underline">{{ $access->user->name }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    @if($access->permissions)
                                        <div class="flex flex-wrap gap-1">
                                            @foreach($access->permissions as $permission)
                                                <x-ui.badge variant="default">{{ $permission }}</x-ui.badge>
                                            @endforeach
                                        </div>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    @if($access->isValid())
                                        <x-ui.badge variant="success">{{ __('Valid') }}</x-ui.badge>
                                    @elseif($access->hasExpired())
                                        <x-ui.badge variant="danger">{{ __('Expired') }}</x-ui.badge>
                                    @elseif($access->isPending())
                                        <x-ui.badge variant="warning">{{ __('Pending') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="default">{{ __('Inactive') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums"><x-ui.datetime :value="$access->access_granted_at" /></td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums"><x-ui.datetime :value="$access->access_expires_at" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No external accesses.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>
</div>
