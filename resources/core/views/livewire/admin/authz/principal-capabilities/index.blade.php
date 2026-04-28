<div>
    <x-slot name="title">{{ __('Principal Capabilities') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Principal Capabilities')" :subtitle="__('Per-user capability overrides — allow or deny specific capabilities outside of role assignments')" />

        <x-ui.card>
            <div class="mb-2">
                <x-ui.search-input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by capability, name, or email...') }}"
                />
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <x-ui.sortable-th
                                column="principal_name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('principal_name')"
                                :label="__('Principal')"
                            />
                            <x-ui.sortable-th
                                column="principal_type"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('principal_type')"
                                :label="__('Type')"
                            />
                            <x-ui.sortable-th
                                column="capability_key"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('capability_key')"
                                :label="__('Capability')"
                            />
                            <x-ui.sortable-th
                                column="is_allowed"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('is_allowed')"
                                :label="__('Access')"
                            />
                            <x-ui.sortable-th
                                column="company_name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('company_name')"
                                :label="__('Company')"
                            />
                            <x-ui.sortable-th
                                column="created_at"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('created_at')"
                                :label="__('Granted At')"
                            />
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($capabilities as $cap)
                            <tr wire:key="cap-{{ $cap->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <div class="text-sm font-medium text-ink">{{ $cap->principal_name ?? '#'.$cap->principal_id }}</div>
                                    @if($cap->principal_email)
                                        <div class="text-muted text-xs">{{ $cap->principal_email }}</div>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    @if($cap->principal_type === \App\Base\Authz\Enums\PrincipalType::USER->value)
                                        <x-ui.badge variant="default">{{ __('User') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="warning">{{ __('Agent') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-sm text-ink">{{ $cap->capability_key }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    @if($cap->is_allowed)
                                        <x-ui.badge variant="success">{{ __('Allow') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="danger">{{ __('Deny') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $cap->company_name ?? '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted"><x-ui.datetime :value="$cap->created_at" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No per-user overrides. Capabilities are currently granted through roles only.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                {{ $capabilities->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
