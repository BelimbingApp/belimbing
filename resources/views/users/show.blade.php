<x-layouts.app :title="$user->name">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="$user->name" :subtitle="__('User details')">
            <x-slot name="actions">
                <form method="POST" action="{{ route('admin.impersonate.start', $user) }}">
                    @csrf
                    <x-ui.button
                        type="submit"
                        variant="ghost"
                        :disabled="$user->id === auth()->id() || session('impersonation.original_user_id')"
                        :title="$user->id === auth()->id() ? __('You cannot impersonate yourself') : (session('impersonation.original_user_id') ? __('Cannot impersonate while impersonating') : __('Impersonate this user'))"
                    >
                        <x-icon name="heroicon-o-impersonate" class="h-4 w-4" />
                        {{ __('Impersonate') }}
                    </x-ui.button>
                </form>
                <x-ui.button variant="ghost" as="a" href="{{ route('admin.users.index') }}">
                    <x-icon name="heroicon-o-arrow-left" class="h-5 w-5" />
                    {{ __('Back') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <h3 class="mb-4 text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('User Details') }}</h3>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Name') }}</dt>
                    <dd class="mt-0.5 text-sm text-ink">{{ $user->name }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Email') }}</dt>
                    <dd class="mt-0.5 text-sm text-ink">{{ $user->email }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Company') }}</dt>
                    <dd class="mt-0.5 text-sm text-ink">{{ $user->company?->name ?? __('None') }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Email Verified') }}</dt>
                    <dd class="mt-0.5 text-sm text-ink">
                        @if ($user->email_verified_at)
                            <x-ui.badge variant="success">{{ $user->email_verified_at->format('Y-m-d H:i') }}</x-ui.badge>
                        @else
                            <x-ui.badge variant="warning">{{ __('Unverified') }}</x-ui.badge>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Created') }}</dt>
                    <dd class="mt-0.5 text-sm tabular-nums text-muted">{{ $user->created_at->format('Y-m-d H:i') }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Updated') }}</dt>
                    <dd class="mt-0.5 text-sm tabular-nums text-muted">{{ $user->updated_at->format('Y-m-d H:i') }}</dd>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted">
                {{ __('Employee Records') }}
                <x-ui.badge>{{ $user->employee ? 1 : 0 }}</x-ui.badge>
            </h3>
            <p class="mb-4 mt-0.5 text-xs text-muted">{{ __('Employment records linked to this user.') }}</p>

            <div class="-mx-card-inner overflow-x-auto px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Employee No.') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Company') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Department') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Designation') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Employment Start') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-default bg-surface-card">
                        @forelse ($user->employee ? [$user->employee] : [] as $employee)
                            <tr class="transition-colors hover:bg-surface-subtle/50">
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm font-medium text-ink">{{ $employee->employee_number ?? '—' }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $employee->company?->name ?? '—' }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $employee->department?->name ?? '—' }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $employee->designation ?? '—' }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y">
                                    <x-ui.badge :variant="match($employee->status) {
                                        'active' => 'success',
                                        'inactive' => 'default',
                                        'terminated' => 'danger',
                                        'pending' => 'warning',
                                        default => 'default',
                                    }">{{ ucfirst($employee->status) }}</x-ui.badge>
                                </td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm tabular-nums text-muted">{{ $employee->employment_start?->format('Y-m-d') ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No employee records.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        <x-ui.card>
            <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted">
                {{ __('External Accesses') }}
                <x-ui.badge>{{ $user->externalAccesses->count() }}</x-ui.badge>
            </h3>
            <p class="mb-4 mt-0.5 text-xs text-muted">{{ __('Portal access granted to this user by other companies.') }}</p>

            <div class="-mx-card-inner overflow-x-auto px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Granting Company') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Permissions') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Granted At') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Expires At') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-default bg-surface-card">
                        @forelse ($user->externalAccesses as $access)
                            <tr class="transition-colors hover:bg-surface-subtle/50">
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $access->company?->name ?? '—' }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">
                                    @if ($access->permissions)
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ($access->permissions as $permission)
                                                <x-ui.badge variant="default">{{ $permission }}</x-ui.badge>
                                            @endforeach
                                        </div>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y">
                                    @if ($access->isValid())
                                        <x-ui.badge variant="success">{{ __('Valid') }}</x-ui.badge>
                                    @elseif ($access->hasExpired())
                                        <x-ui.badge variant="danger">{{ __('Expired') }}</x-ui.badge>
                                    @elseif ($access->isPending())
                                        <x-ui.badge variant="warning">{{ __('Pending') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="default">{{ __('Inactive') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm tabular-nums text-muted">{{ $access->access_granted_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm tabular-nums text-muted">{{ $access->access_expires_at?->format('Y-m-d H:i') ?? '—' }}</td>
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

        <x-ui.card>
            <h3 class="mb-4 text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Available Roles') }}</h3>
            <ul class="space-y-1 text-sm text-ink">
                @foreach ($assignableRoles as $role)
                    <li>{{ $role->name }}</li>
                @endforeach
            </ul>
        </x-ui.card>
    </div>
</x-layouts.app>
