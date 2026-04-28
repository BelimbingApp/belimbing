<div>
    <x-slot name="title">{{ __('Company Management') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Company Management')">
            <x-slot name="actions">
                <x-ui.button
                    variant="primary"
                    as="a"
                    href="{{ route('admin.companies.create') }}"
                    wire:navigate
                >
                    <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                    {{ __('Create Company') }}
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
                        placeholder="{{ __('Search by company name, code, legal name, email, or jurisdiction...') }}"
                    />
                </div>
                <x-ui.select id="company-status-filter" wire:model.live="statusFilter">
                    <option value="all">{{ __('All statuses') }}</option>
                    <option value="active">{{ __('Active') }}</option>
                    <option value="suspended">{{ __('Suspended') }}</option>
                    <option value="pending">{{ __('Pending') }}</option>
                </x-ui.select>
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <x-ui.sortable-th
                                column="name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('name')"
                                :label="__('Company')"
                            />
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Parent') }}</th>
                            <x-ui.sortable-th
                                column="status"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('status')"
                                :label="__('Status')"
                            />
                            <x-ui.sortable-th
                                column="jurisdiction"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('jurisdiction')"
                                :label="__('Jurisdiction')"
                            />
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($companies as $company)
                            <tr wire:key="company-{{ $company->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <a href="{{ route('admin.companies.show', $company) }}" wire:navigate class="text-sm font-medium text-accent hover:underline">{{ $company->name }}</a>
                                    <div class="text-xs text-muted tabular-nums">{{ $company->code }}</div>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    {{ $company->parent?->name ?? __('None') }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <x-ui.badge :variant="$this->statusVariant($company->status)">{{ ucfirst($company->status) }}</x-ui.badge>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    {{ $company->jurisdiction ?? '-' }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        @if ($company->isLicensee())
                                            <x-ui.badge variant="default">{{ __('Licensee') }}</x-ui.badge>
                                        @else
                                            <x-ui.button
                                                variant="danger-ghost"
                                                size="sm"
                                                wire:click="delete({{ $company->id }})"
                                                wire:confirm="{{ __('Are you sure you want to delete this company?') }}"
                                                :title="__('Delete company')"
                                            >
                                                <x-icon name="heroicon-o-trash" class="w-4 h-4" />
                                                <span class="sr-only">{{ __('Delete') }}</span>
                                            </x-ui.button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No companies found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                {{ $companies->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
