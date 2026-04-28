<div>
    <x-slot name="title">{{ __('User Management') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('User Management')">
            <x-slot name="actions">
                <x-ui.button
                    variant="primary"
                    as="a"
                    href="{{ route('admin.users.create') }}"
                    wire:navigate
                >
                    <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                    {{ __('Create User') }}
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
            <div class="mb-2">
                <x-ui.search-input
                    wire:key="users-search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by name or email...') }}"
                />
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
                                :label="__('Name')"
                            />
                            <x-ui.sortable-th
                                column="email"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('email')"
                                :label="__('Email')"
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
                                :label="__('Created')"
                            />
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($users as $user)
                            <tr wire:key="user-{{ $user->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-full bg-surface-subtle flex items-center justify-center shrink-0">
                                            <span class="text-xs font-semibold text-ink">
                                                {{ $user->initials() }}
                                            </span>
                                        </div>
                                        <a href="{{ route('admin.users.show', $user) }}" wire:navigate class="text-sm font-medium text-accent hover:underline">{{ $user->name }}</a>
                                    </div>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $user->email }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    {{ $user->company?->name ?? '—' }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums"><x-ui.datetime :value="$user->created_at" /></td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <form method="POST" action="{{ route('admin.impersonate.start', $user) }}">
                                            @csrf
                                            <x-ui.button
                                                type="submit"
                                                variant="ghost"
                                                size="sm"
                                                :disabled="$user->id === auth()->id() || session('impersonation.original_user_id')"
                                                :title="$user->id === auth()->id() ? __('You cannot impersonate yourself') : (session('impersonation.original_user_id') ? __('Cannot impersonate while impersonating') : __('Impersonate this user'))"
                                            >
                                                <x-icon name="heroicon-o-impersonate" class="w-4 h-4" />
                                                <span class="sr-only">{{ __('Impersonate') }}</span>
                                            </x-ui.button>
                                        </form>
                                        <x-ui.button
                                            variant="danger-ghost"
                                            size="sm"
                                            wire:click="delete({{ $user->id }})"
                                            wire:confirm="{{ __('Are you sure you want to delete this user?') }}"
                                            :disabled="!$canDelete || $user->id === auth()->id()"
                                            :title="!$canDelete ? __('You do not have permission to delete users') : ($user->id === auth()->id() ? __('You cannot delete your own account') : __('Delete user'))"
                                        >
                                            <x-icon name="heroicon-o-trash" class="w-4 h-4" />
                                            <span class="sr-only">{{ __('Delete') }}</span>
                                        </x-ui.button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No users found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                {{ $users->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
