<?php

use App\Base\Authz\Capability\CapabilityRegistry;
use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Authz\Models\RoleCapability;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\Session;
use Livewire\Volt\Component;

new class extends Component
{
    public Role $role;

    public array $selectedCapabilities = [];

    public array $selectedUserIds = [];

    public function mount(Role $role): void
    {
        $this->role = $role->load('capabilities');
    }

    /**
     * Save a field value via inline editing (custom roles only).
     */
    public function saveField(string $field, mixed $value): void
    {
        if (! $this->checkCapability('admin.role.update')) {
            return;
        }

        if ($this->role->is_system) {
            Session::flash('error', __('System roles cannot be edited.'));

            return;
        }

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];

        if (! isset($rules[$field])) {
            return;
        }

        $validated = validator([$field => $value], [$field => $rules[$field]])->validate();

        $this->role->$field = $validated[$field];
        $this->role->save();
    }

    /**
     * Change the company scope of a custom role (only when no users are assigned).
     */
    public function saveScope(?string $companyId): void
    {
        if (! $this->checkCapability('admin.role.update')) {
            return;
        }

        if ($this->role->is_system) {
            Session::flash('error', __('System roles cannot be edited.'));

            return;
        }

        if ($this->role->principalRoles()->exists()) {
            Session::flash('error', __('Cannot change scope while users are assigned to this role.'));

            return;
        }

        $newCompanyId = $companyId !== '' && $companyId !== null ? (int) $companyId : null;

        if ($newCompanyId !== null) {
            $valid = Company::query()
                ->where('id', $newCompanyId)
                ->where(function ($query): void {
                    $query->where('id', Company::LICENSEE_ID)
                        ->orWhere('parent_id', Company::LICENSEE_ID);
                })
                ->exists();

            if (! $valid) {
                return;
            }
        }

        $exists = Role::query()
            ->where('code', $this->role->code)
            ->where('id', '!=', $this->role->id)
            ->when(
                $newCompanyId !== null,
                fn ($q) => $q->where('company_id', $newCompanyId),
                fn ($q) => $q->whereNull('company_id'),
            )
            ->exists();

        if ($exists) {
            Session::flash('error', __('A role with this code already exists in the selected scope.'));

            return;
        }

        $this->role->company_id = $newCompanyId;
        $this->role->save();
        $this->role->load('company');
    }

    /**
     * Delete this custom role.
     */
    public function deleteRole(): void
    {
        if (! $this->checkCapability('admin.role.delete')) {
            return;
        }

        if ($this->role->is_system) {
            Session::flash('error', __('System roles cannot be deleted.'));

            return;
        }

        $this->role->capabilities()->delete();
        $this->role->principalRoles()->delete();
        $this->role->delete();

        $this->redirect(route('admin.roles.index'), navigate: true);
    }

    /**
     * Assign selected capabilities to this role (custom roles only).
     */
    public function assignCapabilities(): void
    {
        if (! $this->checkCapability('admin.role.update')) {
            return;
        }

        if ($this->role->is_system) {
            Session::flash('error', __('System role capabilities are managed by configuration.'));

            return;
        }

        if (empty($this->selectedCapabilities)) {
            return;
        }

        foreach ($this->selectedCapabilities as $capabilityKey) {
            RoleCapability::query()->firstOrCreate([
                'role_id' => $this->role->id,
                'capability_key' => $capabilityKey,
            ]);
        }

        $this->selectedCapabilities = [];
        $this->role->load('capabilities');
    }

    /**
     * Remove a capability from this role (custom roles only).
     */
    public function removeCapability(int $roleCapabilityId): void
    {
        if (! $this->checkCapability('admin.role.update')) {
            return;
        }

        if ($this->role->is_system) {
            Session::flash('error', __('System role capabilities are managed by configuration.'));

            return;
        }

        RoleCapability::query()
            ->where('id', $roleCapabilityId)
            ->where('role_id', $this->role->id)
            ->delete();

        $this->role->load('capabilities');
    }

    /**
     * Assign selected users to this role.
     */
    public function assignUsers(): void
    {
        if (! $this->checkCapability('admin.role.update')) {
            return;
        }

        if (empty($this->selectedUserIds)) {
            return;
        }

        foreach ($this->selectedUserIds as $userId) {
            $user = User::query()->find((int) $userId);

            if ($user === null) {
                continue;
            }

            PrincipalRole::query()->firstOrCreate([
                'company_id' => $user->company_id,
                'principal_type' => PrincipalType::HUMAN_USER->value,
                'principal_id' => $user->id,
                'role_id' => $this->role->id,
            ]);
        }

        $this->selectedUserIds = [];
    }

    /**
     * Remove a user from this role.
     */
    public function removeUser(int $principalRoleId): void
    {
        if (! $this->checkCapability('admin.role.update')) {
            return;
        }

        PrincipalRole::query()
            ->where('id', $principalRoleId)
            ->where('role_id', $this->role->id)
            ->delete();
    }

    /**
     * Check if the current user has the given capability.
     *
     * Flashes a friendly error if denied.
     */
    private function checkCapability(string $capability): bool
    {
        $authUser = auth()->user();

        $actor = new Actor(
            type: PrincipalType::HUMAN_USER,
            id: (int) $authUser->getAuthIdentifier(),
            companyId: $authUser->company_id !== null ? (int) $authUser->company_id : null,
        );

        $decision = app(AuthorizationService::class)->can($actor, $capability);

        if (! $decision->allowed) {
            Session::flash('error', __('You do not have permission to perform this action.'));

            return false;
        }

        return true;
    }

    public function with(): array
    {
        $authUser = auth()->user();

        $authActor = new Actor(
            type: PrincipalType::HUMAN_USER,
            id: (int) $authUser->getAuthIdentifier(),
            companyId: $authUser->company_id !== null ? (int) $authUser->company_id : null,
        );

        $authzService = app(AuthorizationService::class);

        $canEdit = $authzService->can($authActor, 'admin.role.update')->allowed;
        $canDelete = $authzService->can($authActor, 'admin.role.delete')->allowed;

        $allCapabilities = app(CapabilityRegistry::class)->all();
        sort($allCapabilities);

        $assignedKeys = $this->role->capabilities->pluck('capability_key')->all();

        $availableCapabilities = [];
        foreach ($allCapabilities as $capability) {
            if (in_array($capability, $assignedKeys, true)) {
                continue;
            }
            $domain = explode('.', $capability, 2)[0];
            $availableCapabilities[$domain][] = $capability;
        }

        $assignedCapabilities = [];
        foreach ($this->role->capabilities as $cap) {
            $domain = explode('.', $cap->capability_key, 2)[0];
            $assignedCapabilities[$domain][] = $cap;
        }
        ksort($assignedCapabilities);

        $assignedPrincipalRoles = PrincipalRole::query()
            ->with('role')
            ->where('role_id', $this->role->id)
            ->where('principal_type', PrincipalType::HUMAN_USER->value)
            ->get();

        $assignedUserIds = $assignedPrincipalRoles->pluck('principal_id')->all();

        $assignedUsers = User::query()
            ->whereIn('id', $assignedUserIds)
            ->with('company')
            ->orderBy('name')
            ->get()
            ->map(function (User $user) use ($assignedPrincipalRoles) {
                $user->pivot_id = $assignedPrincipalRoles
                    ->where('principal_id', $user->id)
                    ->first()
                    ?->id;

                return $user;
            });

        $availableUsers = $canEdit
            ? User::query()
                ->whereNotIn('id', $assignedUserIds)
                ->with('company')
                ->orderBy('name')
                ->limit(200)
                ->get(['id', 'name', 'email', 'company_id'])
            : collect();

        $licenseeCompanies = Company::query()
            ->where('id', Company::LICENSEE_ID)
            ->orWhere('parent_id', Company::LICENSEE_ID)
            ->orderBy('name')
            ->get(['id', 'name']);

        $hasAssignedUsers = $assignedPrincipalRoles->isNotEmpty();

        return [
            'canEdit' => $canEdit,
            'canDelete' => $canDelete,
            'availableCapabilities' => $availableCapabilities,
            'assignedCapabilities' => $assignedCapabilities,
            'assignedCount' => $this->role->capabilities->count(),
            'assignedUsers' => $assignedUsers,
            'availableUsers' => $availableUsers,
            'licenseeCompanies' => $licenseeCompanies,
            'hasAssignedUsers' => $hasAssignedUsers,
        ];
    }
}; ?>

<div>
    <x-slot name="title">{{ $role->name }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$role->name" :subtitle="$role->description">
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

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @if (! $role->is_system && $canEdit)
                    <div x-data="{ editing: false, val: @js($role->name) }">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Name') }}</dt>
                        <dd class="mt-0.5 text-sm text-ink">
                            <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                <span x-text="val"></span>
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            <input
                                x-show="editing"
                                x-ref="input"
                                x-model="val"
                                @keydown.enter="editing = false; $wire.saveField('name', val)"
                                @keydown.escape="editing = false; val = @js($role->name)"
                                @blur="editing = false; $wire.saveField('name', val)"
                                type="text"
                                class="w-full px-1 -mx-1 py-0.5 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                            />
                        </dd>
                    </div>
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
                    <div x-data="{ editing: false, val: @js((string) ($role->company_id ?? '')) }">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Scope') }}</dt>
                        <dd class="mt-0.5 text-sm text-ink">
                            <div x-show="!editing" @click="editing = true" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                <span>{{ $role->company?->name ?? __('Global') }}</span>
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            <select
                                x-show="editing"
                                x-model="val"
                                @change="editing = false; $wire.saveScope(val)"
                                @keydown.escape="editing = false; val = @js((string) ($role->company_id ?? ''))"
                                @blur="editing = false"
                                class="w-full px-1 -mx-1 py-0.5 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                            >
                                <option value="">{{ __('Global (all companies)') }}</option>
                                @foreach ($licenseeCompanies as $company)
                                    <option value="{{ $company->id }}">{{ $company->name }}</option>
                                @endforeach
                            </select>
                        </dd>
                    </div>
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
                    <div x-data="{ editing: false, val: @js($role->description ?? '') }" class="md:col-span-2">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Description') }}</dt>
                        <dd class="mt-0.5 text-sm text-ink">
                            <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                <span x-text="val || '—'"></span>
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            <input
                                x-show="editing"
                                x-ref="input"
                                x-model="val"
                                @keydown.enter="editing = false; $wire.saveField('description', val)"
                                @keydown.escape="editing = false; val = @js($role->description ?? '')"
                                @blur="editing = false; $wire.saveField('description', val)"
                                type="text"
                                class="w-full px-1 -mx-1 py-0.5 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                            />
                        </dd>
                    </div>
                @else
                    <div>
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Description') }}</dt>
                        <dd class="mt-0.5 text-sm text-ink">{{ $role->description ?? '—' }}</dd>
                    </div>
                @endif
            </div>
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
            <div class="mb-4">
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
            </div>

            {{-- Assign Capabilities --}}
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
                    <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-2">{{ __('Add Users') }}</dt>
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
