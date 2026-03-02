<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Authz\Controllers;

use App\Base\Authz\Capability\CapabilityRegistry;
use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Authz\Models\RoleCapability;
use App\Base\Htmx\Concerns\InteractsWithHtmx;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RoleController
{
    use InteractsWithHtmx;

    /**
     * Show the role list page.
     */
    public function index(Request $request): View
    {
        $search = $request->string('search', '')->toString();

        $authService = app(AuthorizationService::class);
        $canCreate = $authService->can($this->actorFromRequest($request), 'admin.role.create')->allowed;

        $roles = Role::query()
            ->with('company')
            ->withCount('capabilities', 'principalRoles')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('name', 'like', '%'.$search.'%')
                        ->orWhere('code', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        return view('admin.roles.index', compact('canCreate', 'roles', 'search'));
    }

    /**
     * Return the searchable role table fragment for HTMX requests.
     */
    public function search(Request $request): View
    {
        $search = $request->string('search', '')->toString();

        $roles = Role::query()
            ->with('company')
            ->withCount('capabilities', 'principalRoles')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('name', 'like', '%'.$search.'%')
                        ->orWhere('code', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        return view('admin.roles.partials.table', compact('roles', 'search'));
    }

    /**
     * Show the role creation form.
     */
    public function create(): View
    {
        $companies = Company::query()
            ->where('id', Company::LICENSEE_ID)
            ->orWhere('parent_id', Company::LICENSEE_ID)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('admin.roles.create', compact('companies'));
    }

    /**
     * Store a new custom role.
     */
    public function store(Request $request): RedirectResponse
    {
        $companyId = $request->input('company_id');
        if ($companyId === '') {
            $companyId = null;
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'max:255', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('base_authz_roles', 'code')
                    ->when(
                        $companyId !== null,
                        fn ($rule) => $rule->where('company_id', $companyId),
                        fn ($rule) => $rule->whereNull('company_id'),
                    ),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);

        $role = Role::query()->create([
            'company_id' => ($validated['company_id'] ?? null) ? (int) $validated['company_id'] : null,
            'name' => $validated['name'],
            'code' => $validated['code'],
            'description' => $validated['description'] !== '' ? $validated['description'] : null,
            'is_system' => false,
        ]);

        Session::flash('success', __('Role created successfully.'));

        return redirect()->route('admin.roles.show', $role);
    }

    /**
     * Show the role detail page.
     */
    public function show(Request $request, Role $role): View
    {
        $role->load('company', 'capabilities');

        $authService = app(AuthorizationService::class);
        $actor = $this->actorFromRequest($request);

        $canEdit = $authService->can($actor, 'admin.role.update')->allowed;
        $canDelete = $authService->can($actor, 'admin.role.delete')->allowed;

        $allCapabilities = app(CapabilityRegistry::class)->all();
        sort($allCapabilities);

        $assignedKeys = $role->capabilities->pluck('capability_key')->all();

        $availableCapabilities = [];
        foreach ($allCapabilities as $capability) {
            if (in_array($capability, $assignedKeys, true)) {
                continue;
            }

            $domain = explode('.', $capability, 2)[0];
            $availableCapabilities[$domain][] = $capability;
        }

        $assignedCapabilities = [];
        foreach ($role->capabilities as $capability) {
            $domain = explode('.', $capability->capability_key, 2)[0];
            $assignedCapabilities[$domain][] = $capability;
        }
        ksort($assignedCapabilities);

        $assignedPrincipalRoles = PrincipalRole::query()
            ->where('role_id', $role->id)
            ->where('principal_type', PrincipalType::HUMAN_USER->value)
            ->get();

        $assignedUserIds = $assignedPrincipalRoles->pluck('principal_id')->all();

        $assignedUsers = User::query()
            ->whereIn('id', $assignedUserIds)
            ->with('company')
            ->orderBy('name')
            ->get()
            ->map(function (User $user) use ($assignedPrincipalRoles): User {
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

        return view('admin.roles.show', compact(
            'role',
            'canEdit',
            'canDelete',
            'availableCapabilities',
            'assignedCapabilities',
            'assignedUsers',
            'availableUsers',
            'licenseeCompanies',
            'hasAssignedUsers',
        ));
    }

    /**
     * Update editable role details.
     */
    public function update(Request $request, Role $role): RedirectResponse
    {
        if ($role->is_system) {
            Session::flash('error', __('System roles cannot be edited.'));

            return redirect()->route('admin.roles.show', $role);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);

        $companyId = $validated['company_id'] ?? null;
        if ($companyId !== null) {
            $isValidCompany = Company::query()
                ->where('id', $companyId)
                ->where(function ($query): void {
                    $query->where('id', Company::LICENSEE_ID)
                        ->orWhere('parent_id', Company::LICENSEE_ID);
                })
                ->exists();

            if (! $isValidCompany) {
                Session::flash('error', __('Invalid company scope.'));

                return redirect()->route('admin.roles.show', $role);
            }
        }

        if ($role->principalRoles()->exists() && $role->company_id !== $companyId) {
            Session::flash('error', __('Cannot change scope while users are assigned to this role.'));

            return redirect()->route('admin.roles.show', $role);
        }

        $scopeExists = Role::query()
            ->where('code', $role->code)
            ->where('id', '!=', $role->id)
            ->when(
                $companyId !== null,
                fn ($query) => $query->where('company_id', $companyId),
                fn ($query) => $query->whereNull('company_id'),
            )
            ->exists();

        if ($scopeExists) {
            Session::flash('error', __('A role with this code already exists in the selected scope.'));

            return redirect()->route('admin.roles.show', $role);
        }

        $role->name = $validated['name'];
        $role->description = $validated['description'] !== '' ? $validated['description'] : null;
        $role->company_id = $companyId !== null ? (int) $companyId : null;
        $role->save();

        Session::flash('success', __('Role updated successfully.'));

        return redirect()->route('admin.roles.show', $role);
    }

    /**
     * Delete a custom role.
     */
    public function destroy(Role $role): RedirectResponse
    {
        if ($role->is_system) {
            Session::flash('error', __('System roles cannot be deleted.'));

            return redirect()->route('admin.roles.show', $role);
        }

        $role->capabilities()->delete();
        $role->principalRoles()->delete();
        $role->delete();

        Session::flash('success', __('Role deleted successfully.'));

        return redirect()->route('admin.roles.index');
    }

    /**
     * Assign capabilities to a role.
     */
    public function assignCapabilities(Request $request, Role $role): RedirectResponse
    {
        if ($role->is_system) {
            Session::flash('error', __('System role capabilities are managed by configuration.'));

            return redirect()->route('admin.roles.show', $role);
        }

        $validated = $request->validate([
            'selected_capabilities' => ['required', 'array'],
            'selected_capabilities.*' => ['string'],
        ]);

        foreach ($validated['selected_capabilities'] as $capabilityKey) {
            RoleCapability::query()->firstOrCreate([
                'role_id' => $role->id,
                'capability_key' => $capabilityKey,
            ]);
        }

        Session::flash('success', __('Capabilities assigned successfully.'));

        return redirect()->route('admin.roles.show', $role);
    }

    /**
     * Remove a capability from a role.
     */
    public function removeCapability(Role $role, RoleCapability $roleCapability): RedirectResponse
    {
        if ($role->is_system) {
            Session::flash('error', __('System role capabilities are managed by configuration.'));

            return redirect()->route('admin.roles.show', $role);
        }

        if ($roleCapability->role_id !== $role->id) {
            return redirect()->route('admin.roles.show', $role);
        }

        $roleCapability->delete();
        Session::flash('success', __('Capability removed successfully.'));

        return redirect()->route('admin.roles.show', $role);
    }

    /**
     * Assign users to a role.
     */
    public function assignUsers(Request $request, Role $role): RedirectResponse
    {
        $validated = $request->validate([
            'selected_user_ids' => ['required', 'array'],
            'selected_user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        foreach ($validated['selected_user_ids'] as $userId) {
            $user = User::query()->find((int) $userId);
            if ($user === null) {
                continue;
            }

            PrincipalRole::query()->firstOrCreate([
                'company_id' => $user->company_id,
                'principal_type' => PrincipalType::HUMAN_USER->value,
                'principal_id' => $user->id,
                'role_id' => $role->id,
            ]);
        }

        Session::flash('success', __('Users assigned successfully.'));

        return redirect()->route('admin.roles.show', $role);
    }

    /**
     * Remove a user assignment from a role.
     */
    public function removeUser(Role $role, PrincipalRole $principalRole): RedirectResponse
    {
        if ($principalRole->role_id !== $role->id) {
            return redirect()->route('admin.roles.show', $role);
        }

        $principalRole->delete();
        Session::flash('success', __('User removed from role successfully.'));

        return redirect()->route('admin.roles.show', $role);
    }

    /**
     * Build the auth actor for the current request user.
     */
    private function actorFromRequest(Request $request): Actor
    {
        $authUser = $request->user();

        return new Actor(
            type: PrincipalType::HUMAN_USER,
            id: (int) $authUser->getAuthIdentifier(),
            companyId: $authUser->company_id !== null ? (int) $authUser->company_id : null,
        );
    }
}
