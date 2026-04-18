<div>
    <x-slot name="title">{{ __('Principal Roles') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Principal Roles')" :subtitle="__('User and principal role assignments')" />

        <x-ui.card>
            <div class="mb-2">
                <x-ui.search-input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by name, email, or role...') }}"
                />
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Principal') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Type') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Role') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Company') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Assigned At') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($assignments as $assignment)
                            <tr wire:key="assignment-{{ $assignment->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    @if($assignment->principal_name && $assignment->principal_type === \App\Base\Authz\Enums\PrincipalType::USER->value)
                                        <a href="{{ route('admin.users.show', $assignment->principal_id) }}" wire:navigate class="text-sm font-medium text-accent hover:underline">{{ $assignment->principal_name }}</a>
                                        <div class="text-xs text-muted">{{ $assignment->principal_email }}</div>
                                    @elseif($assignment->principal_name)
                                        <div class="text-sm font-medium text-ink">{{ $assignment->principal_name }}</div>
                                        <div class="text-xs text-muted">{{ $assignment->principal_email }}</div>
                                    @else
                                        <div class="text-sm text-muted">{{ __('ID:') }} {{ $assignment->principal_id }}</div>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    @if($assignment->principal_type === \App\Base\Authz\Enums\PrincipalType::USER->value)
                                        <x-ui.badge variant="default">{{ __('User') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="warning">{{ __('Agent') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <a href="{{ route('admin.roles.show', $assignment->role_id) }}" wire:navigate class="text-sm font-medium text-accent hover:underline">{{ $assignment->role->name }}</a>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $assignment->company_name ?? __('Global') }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted"><x-ui.datetime :value="$assignment->created_at" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No principal role assignments found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                {{ $assignments->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
