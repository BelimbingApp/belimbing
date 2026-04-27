<div>
    <x-slot name="title">{{ $role->name }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$role->name" :subtitle="$role->description" :pinnable="['label' => __('Administration') . '/' . __('Authorization') . '/' . __('Roles') . '/' . $role->name, 'url' => route('admin.roles.show', $role)]">
            <x-slot name="actions">
                @if (! $role->is_system && $canDelete)
                    <x-ui.button
                        variant="danger-ghost"
                        wire:click="deleteRole"
                        wire:confirm="{{ __('Delete this role? All capability assignments and user assignments will be removed.') }}"
                    >
                        <x-icon name="heroicon-o-trash" class="w-4 h-4" />
                        {{ __('Delete') }}
                    </x-ui.button>
                @endif
                <x-ui.button variant="ghost" as="a" href="{{ route('admin.roles.index') }}" wire:navigate>
                    <x-icon name="heroicon-o-arrow-left" class="w-5 h-5" />
                    {{ __('Back') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="danger">{{ session('error') }}</x-ui.alert>
        @endif

        {{-- Role Details --}}
        <x-ui.card>
            <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Role Details') }}</h3>

            <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @if (! $role->is_system && $canEdit)
                    <x-ui.edit-in-place.text :label="__('Name')" :value="$role->name" field="name" save-method="saveField" />
                @else
                    <div>
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Name') }}</dt>
                        <dd class="mt-0.5 text-sm text-ink">{{ $role->name }}</dd>
                    </div>
                @endif
                <div>
                    <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Code') }}</dt>
                    <dd class="mt-0.5 text-sm text-ink font-mono text-xs">{{ $role->code }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Type') }}</dt>
                    <dd class="mt-0.5">
                        @if ($role->is_system)
                            <x-ui.badge variant="default">{{ __('System') }}</x-ui.badge>
                        @else
                            <x-ui.badge variant="success">{{ __('Custom') }}</x-ui.badge>
                        @endif
                    </dd>
                </div>
                @if (! $role->is_system && $canEdit && ! $hasAssignedUsers)
                    <x-ui.edit-in-place.select
                        :label="__('Scope')"
                        :value="$role->company_id"
                        save-method="saveScope"
                    >
                        <x-slot name="read">
                            <span class="text-sm text-ink">{{ $role->company?->name ?? __('Global') }}</span>
                        </x-slot>

                        <option value="">{{ __('Global (all companies)') }}</option>
                        @foreach ($licenseeCompanies as $company)
                            <option value="{{ $company->id }}">{{ $company->name }}</option>
                        @endforeach
                    </x-ui.edit-in-place.select>
                @else
                    <div>
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Scope') }}</dt>
                        <dd class="mt-0.5 text-sm text-ink">
                            {{ $role->company?->name ?? __('Global') }}
                            @if (! $role->is_system && $canEdit && $hasAssignedUsers)
                                <span class="text-xs text-muted">({{ __('remove users to change') }})</span>
                            @endif
                        </dd>
                    </div>
                @endif
                @if (! $role->is_system && $canEdit)
                    <x-ui.edit-in-place.text
                        class="md:col-span-2"
                        :label="__('Description')"
                        :value="$role->description"
                        field="description"
                        save-method="saveField"
                        empty="—"
                    />
                @else
                    <div>
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Description') }}</dt>
                        <dd class="mt-0.5 text-sm text-ink">{{ $role->description ?? '—' }}</dd>
                    </div>
                @endif
            </dl>
        </x-ui.card>

        {{-- Capabilities --}}
        <x-ui.card>
            <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">
                {{ __('Capabilities') }}
                <x-ui.badge>{{ $assignedCount }}</x-ui.badge>
            </h3>
            <p class="text-xs text-muted mt-0.5 mb-4">{{ __('Capabilities define specific actions this role can perform. Changes affect all users assigned this role.') }}</p>

            @if ($role->is_system)
                <x-ui.alert variant="info" class="mb-4">{{ __('System role capabilities are managed by configuration and cannot be changed here.') }}</x-ui.alert>
            @endif

            {{-- Assigned Capabilities --}}
            <dl class="mb-4">
                <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-2">{{ __('Capabilities') }}</dt>
                <dd>
                    @if (empty($assignedCapabilities))
                        <span class="text-sm text-muted">{{ __('No capabilities assigned.') }}</span>
                    @else
                        @foreach ($assignedCapabilities as $domain => $caps)
                            <div class="mb-3">
                                <div class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-1">{{ $domain }}</div>
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($caps as $cap)
                                        <span class="inline-flex items-center gap-1">
                                            <x-ui.badge variant="success">
                                                {{ $cap->capability_key }}
                                                @if ($canEdit && ! $role->is_system)
                                                    <button
                                                        type="button"
                                                        wire:click="removeCapability({{ $cap->id }})"
                                                        class="ml-1 text-current opacity-60 hover:opacity-100 transition-opacity"
                                                        title="{{ __('Remove capability') }}"
                                                        aria-label="{{ __('Remove capability') }}"
                                                    >
                                                        <x-icon name="heroicon-o-x-mark" class="w-3.5 h-3.5 stroke-[2.5]" />
                                                    </button>
                                                @endif
                                            </x-ui.badge>
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    @endif
                </dd>
            </dl>
            @if ($canEdit && ! $role->is_system && ! empty($availableCapabilities))
                <div
                    x-data="{ capFilter: '', selected: @entangle('selectedCapabilities') }"
                    class="mb-6"
                >
                    <div>
                        <x-ui.search-input
                            x-model="capFilter"
                            placeholder="{{ __('Search capabilities...') }}"
                        />
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-1 mt-2 max-h-48 overflow-y-auto">
                        @foreach ($availableCapabilities as $domain => $capabilities)
                            @foreach ($capabilities as $capability)
                                <label
                                    x-show="!capFilter || @js(strtolower($capability)).includes(capFilter.toLowerCase())"
                                    class="flex items-center gap-2 px-2 py-1 rounded text-sm hover:bg-surface-subtle cursor-pointer"
                                >
                                    <input
                                        type="checkbox"
                                        value="{{ $capability }}"
                                        x-model="selected"
                                        class="rounded border-border-input accent-accent focus:ring-accent"
                                    >
                                    <span class="text-ink truncate" title="{{ $capability }}">{{ $capability }}</span>
                                </label>
                            @endforeach
                        @endforeach
                    </div>
                    <div x-show="selected.length > 0" x-cloak class="mt-2">
                        <x-ui.button variant="primary" size="sm" wire:click="assignCapabilities">
                            {{ __('Assign') }} <span x-text="'(' + selected.length + ')'"></span>
                        </x-ui.button>
                    </div>
                </div>
            @endif
        </x-ui.card>

        {{-- Assigned Users --}}
        <x-ui.card>
            <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">
                {{ __('Assigned Users') }}
                <x-ui.badge>{{ $assignedUsers->count() }}</x-ui.badge>
            </h3>
            <p class="text-xs text-muted mt-0.5 mb-4">{{ __('Users who have been assigned this role.') }}</p>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Name') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Email') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Company') }}</th>
                            @if ($canEdit)
                                <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($assignedUsers as $assignedUser)
                            <tr wire:key="user-{{ $assignedUser->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <a href="{{ route('admin.users.show', $assignedUser) }}" wire:navigate class="text-sm font-medium text-accent hover:underline">{{ $assignedUser->name }}</a>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $assignedUser->email }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $assignedUser->company?->name ?? '—' }}</td>
                                @if ($canEdit)
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                        <button
                                            type="button"
                                            wire:click="removeUser({{ $assignedUser->pivot_id }})"
                                            wire:confirm="{{ __('Remove :name from this role?', ['name' => $assignedUser->name]) }}"
                                            class="text-muted hover:text-status-danger transition-colors"
                                            title="{{ __('Remove user') }}"
                                            aria-label="{{ __('Remove user') }}"
                                        >
                                            <x-icon name="heroicon-o-x-mark" class="w-4 h-4" />
                                        </button>
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

            {{-- Assign Users --}}
            @if ($canEdit && $availableUsers->isNotEmpty())
                <div
                    x-data="{ userFilter: '', selected: @entangle('selectedUserIds') }"
                    class="mt-4 pt-4 border-t border-border-default"
                >
                    <p class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-2">{{ __('Add Users') }}</p>
                    <x-ui.search-input
                        x-model="userFilter"
                        placeholder="{{ __('Search users by name or email...') }}"
                    />
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-1 mt-2 max-h-48 overflow-y-auto">
                        @foreach ($availableUsers as $availableUser)
                            <label
                                x-show="!userFilter || @js(strtolower($availableUser->name . ' ' . $availableUser->email)).includes(userFilter.toLowerCase())"
                                class="flex items-center gap-2 px-2 py-1 rounded text-sm hover:bg-surface-subtle cursor-pointer"
                            >
                                <input
                                    type="checkbox"
                                    value="{{ $availableUser->id }}"
                                    x-model="selected"
                                    class="rounded border-border-input accent-accent focus:ring-accent"
                                >
                                <span class="text-ink truncate" title="{{ $availableUser->email }}">{{ $availableUser->name }}</span>
                                <span class="text-muted text-xs truncate">{{ $availableUser->email }}</span>
                            </label>
                        @endforeach
                    </div>
                    <div x-show="selected.length > 0" x-cloak class="mt-2">
                        <x-ui.button variant="primary" size="sm" wire:click="assignUsers">
                            {{ __('Assign') }} <span x-text="'(' + selected.length + ')'"></span>
                        </x-ui.button>
                    </div>
                </div>
            @endif
        </x-ui.card>
    </div>
</div>
