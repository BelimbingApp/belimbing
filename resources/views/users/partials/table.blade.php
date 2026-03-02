<div class="-mx-card-inner overflow-x-auto px-card-inner">
    <table class="min-w-full divide-y divide-border-default text-sm">
        <thead class="bg-surface-subtle/80">
            <tr>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Name') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Email') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Company') }}</th>
                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Created') }}</th>
                <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Actions') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-border-default bg-surface-card">
            @forelse ($users as $user)
                <tr class="transition-colors hover:bg-surface-subtle/50">
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y">
                        <div class="flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-surface-subtle">
                                <span class="text-xs font-semibold text-ink">{{ $user->initials() }}</span>
                            </div>
                            <a href="{{ route('admin.users.show', $user) }}" class="text-sm font-medium text-accent hover:underline">{{ $user->name }}</a>
                        </div>
                    </td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $user->email }}</td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $user->company?->name ?? '—' }}</td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm tabular-nums text-muted">{{ $user->created_at->format('Y-m-d H:i') }}</td>
                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-right">
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
                                    <x-icon name="heroicon-o-impersonate" class="h-4 w-4" />
                                    {{ __('Impersonate') }}
                                </x-ui.button>
                            </form>

                            <form method="POST" action="{{ route('admin.users.destroy', $user) }}" onsubmit="return confirm('{{ __('Are you sure you want to delete this user?') }}')">
                                @csrf
                                @method('DELETE')
                                <x-ui.button
                                    type="submit"
                                    variant="danger-ghost"
                                    size="sm"
                                    :disabled="!$canDelete || $user->id === auth()->id()"
                                    :title="!$canDelete ? __('You do not have permission to delete users') : ($user->id === auth()->id() ? __('You cannot delete your own account') : null)"
                                >
                                    <x-icon name="heroicon-o-trash" class="h-4 w-4" />
                                    {{ __('Delete') }}
                                </x-ui.button>
                            </form>
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
    {{ $users->withQueryString()->links() }}
</div>
