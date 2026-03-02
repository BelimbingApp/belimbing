<x-layouts.app :title="$role->name">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="$role->name" :subtitle="$role->description">
            <x-slot name="actions">
                @if (! $role->is_system && $canDelete)
                    <form method="POST" action="{{ route('admin.roles.destroy', $role) }}" onsubmit="return confirm('{{ __('Delete this role? All capability assignments and user assignments will be removed.') }}')">
                        @csrf
                        @method('DELETE')
                        <x-ui.button type="submit" variant="danger-ghost">
                            <x-icon name="heroicon-o-trash" class="h-4 w-4" />
                            {{ __('Delete') }}
                        </x-ui.button>
                    </form>
                @endif
                <x-ui.button variant="ghost" as="a" href="{{ route('admin.roles.index') }}">
                    <x-icon name="heroicon-o-arrow-left" class="h-5 w-5" />
                    {{ __('Back') }}
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
            <h3 class="mb-4 text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Role Details') }}</h3>

            @if ($canEdit && ! $role->is_system)
                <form method="POST" action="{{ route('admin.roles.update', $role) }}" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    @csrf
                    @method('PATCH')

                    <x-ui.input name="name" :value="old('name', $role->name)" label="{{ __('Name') }}" required :error="$errors->first('name')" />

                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Code') }}</dt>
                        <dd class="mt-0.5 font-mono text-xs text-ink">{{ $role->code }}</dd>
                    </div>

                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Type') }}</dt>
                        <dd class="mt-0.5">
                            @if ($role->is_system)
                                <x-ui.badge variant="default">{{ __('System') }}</x-ui.badge>
                            @else
                                <x-ui.badge variant="success">{{ __('Custom') }}</x-ui.badge>
                            @endif
                        </dd>
                    </div>

                    <x-ui.select name="company_id" label="{{ __('Scope') }}" :disabled="$hasAssignedUsers" :error="$errors->first('company_id')">
                        <option value="">{{ __('Global (all companies)') }}</option>
                        @foreach ($licenseeCompanies as $company)
                            <option value="{{ $company->id }}" @selected((string) old('company_id', $role->company_id) === (string) $company->id)>{{ $company->name }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.textarea name="description" label="{{ __('Description') }}" rows="3" class="md:col-span-2" :error="$errors->first('description')">{{ old('description', $role->description) }}</x-ui.textarea>

                    <div class="md:col-span-2">
                        @if ($hasAssignedUsers)
                            <p class="mb-2 text-xs text-muted">{{ __('Scope cannot be changed while users are assigned to this role.') }}</p>
                        @endif
                        <x-ui.button type="submit" variant="primary">{{ __('Save Changes') }}</x-ui.button>
                    </div>
                </form>
            @else
                <dl class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Name') }}</dt>
                        <dd class="mt-0.5 text-sm text-ink">{{ $role->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Code') }}</dt>
                        <dd class="mt-0.5 font-mono text-xs text-ink">{{ $role->code }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Type') }}</dt>
                        <dd class="mt-0.5">
                            @if ($role->is_system)
                                <x-ui.badge variant="default">{{ __('System') }}</x-ui.badge>
                            @else
                                <x-ui.badge variant="success">{{ __('Custom') }}</x-ui.badge>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Scope') }}</dt>
                        <dd class="mt-0.5 text-sm text-ink">{{ $role->company?->name ?? __('Global') }}</dd>
                    </div>
                    <div class="md:col-span-2">
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Description') }}</dt>
                        <dd class="mt-0.5 text-sm text-ink">{{ $role->description ?? '—' }}</dd>
                    </div>
                </dl>
            @endif
        </x-ui.card>

        <x-ui.card>
            <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted">
                {{ __('Capabilities') }}
                <x-ui.badge>{{ $role->capabilities->count() }}</x-ui.badge>
            </h3>
            <p class="mb-4 mt-0.5 text-xs text-muted">{{ __('Capabilities define specific actions this role can perform. Changes affect all users assigned this role.') }}</p>

            @if ($role->is_system)
                <x-ui.alert variant="info" class="mb-4">{{ __('System role capabilities are managed by configuration and cannot be changed here.') }}</x-ui.alert>
            @endif

            <div class="mb-4">
                @if (empty($assignedCapabilities))
                    <p class="text-sm text-muted">{{ __('No capabilities assigned.') }}</p>
                @else
                    @foreach ($assignedCapabilities as $domain => $capabilities)
                        <div class="mb-3">
                            <div class="mb-1 text-[11px] font-semibold uppercase tracking-wider text-muted">{{ $domain }}</div>
                            <div class="flex flex-wrap gap-1">
                                @foreach ($capabilities as $capability)
                                    <span class="inline-flex items-center gap-1">
                                        <x-ui.badge variant="success">{{ $capability->capability_key }}</x-ui.badge>
                                        @if ($canEdit && ! $role->is_system)
                                            <form method="POST" action="{{ route('admin.roles.capabilities.destroy', [$role, $capability]) }}" onsubmit="return confirm('{{ __('Remove capability?') }}')">
                                                @csrf
                                                @method('DELETE')
                                                <x-ui.button type="submit" size="sm" variant="ghost">
                                                    <x-icon name="heroicon-o-x-mark" class="h-4 w-4" />
                                                </x-ui.button>
                                            </form>
                                        @endif
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                @endif
            </div>

            @if ($canEdit && ! $role->is_system && ! empty($availableCapabilities))
                <form method="POST" action="{{ route('admin.roles.capabilities.store', $role) }}" class="space-y-3">
                    @csrf
                    <div class="grid max-h-48 grid-cols-1 gap-1 overflow-y-auto sm:grid-cols-2 md:grid-cols-3">
                        @foreach ($availableCapabilities as $domain => $capabilities)
                            @foreach ($capabilities as $capability)
                                <label class="flex items-center gap-2 rounded px-2 py-1 text-sm hover:bg-surface-subtle">
                                    <input type="checkbox" name="selected_capabilities[]" value="{{ $capability }}" class="rounded border-border-input text-accent focus:ring-accent">
                                    <span class="truncate text-ink" title="{{ $capability }}">{{ $capability }}</span>
                                </label>
                            @endforeach
                        @endforeach
                    </div>
                    <x-ui.button type="submit" variant="primary" size="sm">{{ __('Assign Capabilities') }}</x-ui.button>
                </form>
            @endif
        </x-ui.card>

        <x-ui.card>
            <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted">
                {{ __('Assigned Users') }}
                <x-ui.badge>{{ $assignedUsers->count() }}</x-ui.badge>
            </h3>
            <p class="mb-4 mt-0.5 text-xs text-muted">{{ __('Users who have been assigned this role.') }}</p>

            <div class="-mx-card-inner overflow-x-auto px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Name') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Email') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Company') }}</th>
                            @if ($canEdit)
                                <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Actions') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-default bg-surface-card">
                        @forelse ($assignedUsers as $assignedUser)
                            <tr class="transition-colors hover:bg-surface-subtle/50">
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y">
                                    <a href="{{ route('admin.users.show', $assignedUser) }}" class="text-sm font-medium text-accent hover:underline">{{ $assignedUser->name }}</a>
                                </td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $assignedUser->email }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $assignedUser->company?->name ?? '—' }}</td>
                                @if ($canEdit)
                                    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-right">
                                        <form method="POST" action="{{ route('admin.roles.users.destroy', [$role, $assignedUser->pivot_id]) }}" onsubmit="return confirm('{{ __('Remove :name from this role?', ['name' => $assignedUser->name]) }}')">
                                            @csrf
                                            @method('DELETE')
                                            <x-ui.button type="submit" size="sm" variant="ghost">
                                                <x-icon name="heroicon-o-x-mark" class="h-4 w-4" />
                                            </x-ui.button>
                                        </form>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $canEdit ? 4 : 3 }}" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No users assigned.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($canEdit && $availableUsers->isNotEmpty())
                <form method="POST" action="{{ route('admin.roles.users.store', $role) }}" class="mt-4 space-y-3 border-t border-border-default pt-4">
                    @csrf
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Add Users') }}</dt>
                    <div class="grid max-h-48 grid-cols-1 gap-1 overflow-y-auto sm:grid-cols-2 md:grid-cols-3">
                        @foreach ($availableUsers as $availableUser)
                            <label class="flex items-center gap-2 rounded px-2 py-1 text-sm hover:bg-surface-subtle">
                                <input type="checkbox" name="selected_user_ids[]" value="{{ $availableUser->id }}" class="rounded border-border-input text-accent focus:ring-accent">
                                <span class="truncate text-ink" title="{{ $availableUser->email }}">{{ $availableUser->name }}</span>
                                <span class="truncate text-xs text-muted">{{ $availableUser->email }}</span>
                            </label>
                        @endforeach
                    </div>
                    <x-ui.button type="submit" variant="primary" size="sm">{{ __('Assign Users') }}</x-ui.button>
                </form>
            @endif
        </x-ui.card>
    </div>
</x-layouts.app>
