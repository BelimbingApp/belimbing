<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\User\Controllers;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Authz\Models\Role;
use App\Base\Htmx\Concerns\InteractsWithHtmx;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class UserController
{
    use InteractsWithHtmx;

    /**
     * Show the user list page.
     */
    public function index(Request $request): View
    {
        $search = $request->string('search', '')->toString();

        $authUser = $request->user();
        $actor = new Actor(
            type: PrincipalType::HUMAN_USER,
            id: (int) $authUser->getAuthIdentifier(),
            companyId: $authUser->company_id !== null ? (int) $authUser->company_id : null,
        );

        $canDelete = app(AuthorizationService::class)
            ->can($actor, 'core.user.delete')
            ->allowed;

        $users = User::query()
            ->with('company')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('users.index', compact('users', 'canDelete', 'search'));
    }

    /**
     * Return the searchable table fragment for HTMX requests.
     */
    public function search(Request $request): View
    {
        $search = $request->string('search', '')->toString();

        $authUser = $request->user();
        $actor = new Actor(
            type: PrincipalType::HUMAN_USER,
            id: (int) $authUser->getAuthIdentifier(),
            companyId: $authUser->company_id !== null ? (int) $authUser->company_id : null,
        );

        $canDelete = app(AuthorizationService::class)
            ->can($actor, 'core.user.delete')
            ->allowed;

        $users = User::query()
            ->with('company')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('users.partials.table', compact('users', 'canDelete', 'search'));
    }

    /**
     * Show the create-user form.
     */
    public function create(): View
    {
        $companies = Company::query()->orderBy('name')->get(['id', 'name']);

        return view('users.create', compact('companies'));
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request): RedirectResponse
    {
        $companyId = $request->input('company_id');
        if ($companyId === '') {
            $companyId = null;
        }

        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', Rule::exists(Company::class, 'id')],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        $validated['password'] = Hash::make($validated['password']);
        $validated['company_id'] = ($validated['company_id'] ?? null) ? (int) $validated['company_id'] : null;

        User::query()->create($validated);

        Session::flash('success', __('User created successfully.'));

        return redirect()->route('admin.users.index');
    }

    /**
     * Show the user detail page.
     */
    public function show(User $user): View
    {
        $user->load('company', 'employee.company', 'employee.department', 'externalAccesses.company');
        $assignableRoles = Role::query()->orderBy('name')->get(['id', 'name']);

        return view('users.show', compact('user', 'assignableRoles'));
    }

    /**
     * Update a user inline-editable field.
     */
    public function updateField(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'field' => ['required', 'in:name,email'],
        ]);

        if ($validated['field'] === 'name') {
            $nameValidated = $request->validate([
                'value' => ['required', 'string', 'max:255'],
            ]);

            $user->name = $nameValidated['value'];
        }

        if ($validated['field'] === 'email') {
            $emailValidated = $request->validate([
                'value' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            ]);

            $newEmail = $emailValidated['value'];
            if ($newEmail !== $user->email) {
                $user->email_verified_at = null;
            }
            $user->email = $newEmail;
        }

        $user->save();
        Session::flash('success', __('User updated successfully.'));

        return redirect()->route('admin.users.show', $user);
    }

    /**
     * Update a user's company assignment.
     */
    public function updateCompany(Request $request, User $user): RedirectResponse
    {
        $companyId = $request->input('company_id');
        if ($companyId === '') {
            $companyId = null;
        }

        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', Rule::exists(Company::class, 'id')],
        ]);

        $user->company_id = ($validated['company_id'] ?? null) ? (int) $validated['company_id'] : null;
        $user->save();
        Session::flash('success', __('User company updated successfully.'));

        return redirect()->route('admin.users.show', $user);
    }

    /**
     * Update a user's password as an administrator.
     */
    public function updatePassword(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        $user->password = Hash::make($validated['password']);
        $user->save();
        Session::flash('success', __('User password updated successfully.'));

        return redirect()->route('admin.users.show', $user);
    }

    /**
     * Assign direct capabilities to a user.
     */
    public function storeCapabilities(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'selected_capability_keys' => ['required', 'array'],
            'selected_capability_keys.*' => ['string'],
        ]);

        foreach ($validated['selected_capability_keys'] as $capabilityKey) {
            PrincipalCapability::query()->updateOrCreate(
                [
                    'company_id' => $user->company_id,
                    'principal_type' => PrincipalType::HUMAN_USER->value,
                    'principal_id' => $user->id,
                    'capability_key' => $capabilityKey,
                ],
                ['is_allowed' => true]
            );
        }

        Session::flash('success', __('Capabilities assigned successfully.'));

        return redirect()->route('admin.users.show', $user);
    }

    /**
     * Remove a direct capability entry from a user.
     */
    public function destroyCapability(User $user, PrincipalCapability $principalCapability): RedirectResponse
    {
        if (
            $principalCapability->principal_type === PrincipalType::HUMAN_USER->value
            && $principalCapability->principal_id === $user->id
        ) {
            $principalCapability->delete();
            Session::flash('success', __('Capability removed successfully.'));
        }

        return redirect()->route('admin.users.show', $user);
    }

    /**
     * Add or update a denied capability for a user.
     */
    public function denyCapability(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'capability_key' => ['required', 'string'],
        ]);

        PrincipalCapability::query()->updateOrCreate(
            [
                'company_id' => $user->company_id,
                'principal_type' => PrincipalType::HUMAN_USER->value,
                'principal_id' => $user->id,
                'capability_key' => $validated['capability_key'],
            ],
            ['is_allowed' => false]
        );

        Session::flash('success', __('Capability denied successfully.'));

        return redirect()->route('admin.users.show', $user);
    }

    /**
     * Delete a user.
     */
    public function destroy(Request $request, User $user): RedirectResponse|Response
    {
        $authUser = $request->user();
        $actor = new Actor(
            type: PrincipalType::HUMAN_USER,
            id: (int) $authUser->getAuthIdentifier(),
            companyId: $authUser->company_id !== null ? (int) $authUser->company_id : null,
        );

        try {
            app(AuthorizationService::class)->authorize($actor, 'core.user.delete');
        } catch (AuthorizationDeniedException) {
            Session::flash('error', __('You do not have permission to delete users.'));

            return redirect()->route('admin.users.index');
        }

        if ($user->id === $authUser->getAuthIdentifier()) {
            Session::flash('error', __('You cannot delete your own account.'));

            return redirect()->route('admin.users.index');
        }

        $user->delete();
        Session::flash('success', __('User deleted successfully.'));

        return redirect()->route('admin.users.index');
    }
}
