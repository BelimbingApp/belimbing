<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Authz\Livewire\Roles;

use App\Base\Authz\Capability\CapabilityRegistry;
use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Livewire\Concerns\ChecksCapabilityAuthorization;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Authz\Models\RoleCapability;
use App\Base\Foundation\Livewire\Concerns\SavesValidatedFields;
use App\Modules\Core\AI\Contracts\ProvidesLaraPageContext;
use App\Modules\Core\AI\DTO\PageContext;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Livewire\Component;

class Show extends Component implements ProvidesLaraPageContext
{
    use ChecksCapabilityAuthorization;
    use SavesValidatedFields;

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

        $this->saveValidatedField($this->role, $field, $value, $rules);
    }

    /**
     * Change the company scope of a custom role (only when no users are assigned).
     */
    public function saveScope(?string $companyId): void
    {
        if ($this->checkCapability('admin.role.update')) {
            $newCompanyId = $companyId !== '' && $companyId !== null ? (int) $companyId : null;

            if ($this->role->is_system) {
                Session::flash('error', __('System roles cannot be edited.'));
            } elseif ($this->role->principalRoles()->exists()) {
                Session::flash('error', __('Cannot change scope while users are assigned to this role.'));
            } elseif ($this->isInvalidScopeCompany($newCompanyId)) {
                Session::flash('error', __('The selected company is not valid for this role scope.'));
            } elseif ($this->scopeConflictExists($newCompanyId)) {
                Session::flash('error', __('A role with this code already exists in the selected scope.'));
            } else {
                $this->role->company_id = $newCompanyId;
                $this->role->save();
                $this->role->load('company');
            }
        }
    }

    private function isInvalidScopeCompany(?int $newCompanyId): bool
    {
        if ($newCompanyId === null) {
            return false;
        }

        return ! Company::query()
            ->where('id', $newCompanyId)
            ->where(function ($query): void {
                $query->where('id', Company::LICENSEE_ID)
                    ->orWhere('parent_id', Company::LICENSEE_ID);
            })
            ->exists();
    }

    private function scopeConflictExists(?int $newCompanyId): bool
    {
        return Role::query()
            ->where('code', $this->role->code)
            ->where('id', '!=', $this->role->id)
            ->when(
                $newCompanyId !== null,
                fn ($q) => $q->where('company_id', $newCompanyId),
                fn ($q) => $q->whereNull('company_id'),
            )
            ->exists();
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

    public function render(): View
    {
        $authUser = auth()->user();

        $authActor = Actor::forUser($authUser);

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

        return view('livewire.admin.roles.show', [
            'canEdit' => $canEdit,
            'canDelete' => $canDelete,
            'availableCapabilities' => $availableCapabilities,
            'assignedCapabilities' => $assignedCapabilities,
            'assignedCount' => $this->role->capabilities->count(),
            'assignedUsers' => $assignedUsers,
            'availableUsers' => $availableUsers,
            'licenseeCompanies' => $licenseeCompanies,
            'hasAssignedUsers' => $hasAssignedUsers,
        ]);
    }

    public function pageContext(): PageContext
    {
        return new PageContext(
            route: 'admin.roles.show',
            url: route('admin.roles.show', $this->role),
            title: $this->role->name,
            module: 'Role',
            resourceType: 'role',
            resourceId: $this->role->id,
            visibleActions: $this->role->is_system
                ? ['View capabilities', 'View assigned users']
                : ['Edit role', 'Assign capabilities', 'Assign users', 'Delete role'],
        );
    }
}
