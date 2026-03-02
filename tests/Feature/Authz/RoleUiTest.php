<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $roles = config('authz.roles', []);

    foreach ($roles as $code => $roleDef) {
        $role = Role::query()->firstOrCreate(
            ['company_id' => null, 'code' => $code],
            ['name' => $roleDef['name'], 'description' => $roleDef['description'] ?? null, 'is_system' => true, 'grant_all' => $roleDef['grant_all'] ?? false]
        );

        $now = now();

        foreach ($roleDef['capabilities'] ?? [] as $capKey) {
            DB::table('base_authz_role_capabilities')->insertOrIgnore([
                'role_id' => $role->id,
                'capability_key' => strtolower($capKey),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
});

/**
 * Create a user with core_admin role for tests that need authz capabilities.
 */
function createRoleTestAdmin(): User
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();

    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::HUMAN_USER->value,
        'principal_id' => $user->id,
        'role_id' => $role->id,
    ]);

    return $user;
}

test('guests are redirected to login from role pages', function (): void {
    $role = Role::query()->first();

    $this->get(route('admin.roles.index'))->assertRedirect(route('login'));
    $this->get(route('admin.roles.show', $role))->assertRedirect(route('login'));
});

test('authenticated users with capability can view role pages', function (): void {
    $user = createRoleTestAdmin();
    $role = Role::query()->first();

    $this->actingAs($user);

    $this->get(route('admin.roles.index'))->assertOk();
    $this->get(route('admin.roles.show', $role))->assertOk();
});

test('authenticated users without capability are denied role pages', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $role = Role::query()->first();

    $this->actingAs($user);

    $this->get(route('admin.roles.index'))->assertStatus(403);
    $this->get(route('admin.roles.show', $role))->assertStatus(403);
});

test('role index displays roles with search', function (): void {
    $user = createRoleTestAdmin();

    $indexResponse = $this->actingAs($user)->get(route('admin.roles.index'));
    $indexResponse->assertOk()->assertSee('Core Administrator')->assertSee('User Viewer')->assertSee('User Editor');

    $searchResponse = $this->actingAs($user)->get(route('admin.roles.index', ['search' => 'viewer']));
    $searchResponse->assertOk()->assertSee('User Viewer')->assertDontSee('Core Administrator');
});

test('role show displays role details and capabilities', function (): void {
    $user = createRoleTestAdmin();
    $role = Role::query()->where('code', 'user_viewer')->firstOrFail();

    $response = $this->actingAs($user)->get(route('admin.roles.show', $role));

    $response->assertOk()->assertSee('User Viewer')->assertSee('user_viewer')->assertSee('core.user.view');
});

test('capabilities can be assigned to a custom role', function (): void {
    $user = createRoleTestAdmin();

    $role = Role::query()->create([
        'company_id' => $user->company_id,
        'name' => 'Assignable Role',
        'code' => 'assignable_role',
        'is_system' => false,
    ]);

    $this->actingAs($user)->post(route('admin.roles.capabilities.store', $role), [
        'selected_capabilities' => ['core.user.create'],
    ])->assertRedirect(route('admin.roles.show', $role));

    expect($role->capabilities()->count())->toBe(1);
    expect($role->capabilities()->where('capability_key', 'core.user.create')->exists())->toBeTrue();
});

test('capabilities can be removed from a custom role', function (): void {
    $user = createRoleTestAdmin();

    $role = Role::query()->create([
        'company_id' => $user->company_id,
        'name' => 'Removable Role',
        'code' => 'removable_role',
        'is_system' => false,
    ]);

    $now = now();
    DB::table('base_authz_role_capabilities')->insert([
        'role_id' => $role->id,
        'capability_key' => 'core.user.view',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $cap = $role->capabilities()->where('capability_key', 'core.user.view')->first();

    $this->actingAs($user)->delete(route('admin.roles.capabilities.destroy', [$role, $cap]))->assertRedirect(route('admin.roles.show', $role));

    expect($role->capabilities()->where('capability_key', 'core.user.view')->exists())->toBeFalse();
});

test('custom role can be created', function (): void {
    $user = createRoleTestAdmin();

    $response = $this->actingAs($user)->post(route('admin.roles.store'), [
        'name' => 'Test Custom Role',
        'code' => 'test_custom',
        'description' => 'A test custom role',
        'company_id' => $user->company_id,
    ]);

    $response->assertRedirect();

    $role = Role::query()->where('code', 'test_custom')->first();

    expect($role)->not->toBeNull();
    expect($role->name)->toBe('Test Custom Role');
    expect($role->is_system)->toBeFalse();
    expect($role->company_id)->toBe($user->company_id);
});

test('duplicate role code in same scope returns validation error', function (): void {
    $user = createRoleTestAdmin();

    Role::query()->create([
        'company_id' => $user->company_id,
        'name' => 'Existing Role',
        'code' => 'duplicate_code',
        'is_system' => false,
    ]);

    $response = $this->actingAs($user)->from(route('admin.roles.create'))->post(route('admin.roles.store'), [
        'name' => 'Another Role',
        'code' => 'duplicate_code',
        'company_id' => $user->company_id,
    ]);

    $response->assertSessionHasErrors(['code']);
});

test('same role code in different company scope is allowed', function (): void {
    $user = createRoleTestAdmin();

    Company::query()->find(Company::LICENSEE_ID)
        ?? Company::factory()->create(['id' => Company::LICENSEE_ID]);
    $otherCompany = Company::factory()->create(['parent_id' => Company::LICENSEE_ID]);

    Role::query()->create([
        'company_id' => $otherCompany->id,
        'name' => 'Existing Role',
        'code' => 'shared_code',
        'is_system' => false,
    ]);

    $response = $this->actingAs($user)->post(route('admin.roles.store'), [
        'name' => 'My Role',
        'code' => 'shared_code',
        'company_id' => Company::LICENSEE_ID,
    ]);

    $response->assertSessionHasNoErrors();

    expect(Role::query()->where('code', 'shared_code')->count())->toBeGreaterThanOrEqual(1);
});

test('system role capabilities cannot be modified via UI', function (): void {
    $user = createRoleTestAdmin();
    $role = Role::query()->where('code', 'user_viewer')->firstOrFail();

    $initialCount = $role->capabilities()->count();

    $this->actingAs($user)->post(route('admin.roles.capabilities.store', $role), [
        'selected_capabilities' => ['core.geonames.view'],
    ])->assertRedirect(route('admin.roles.show', $role));

    expect($role->capabilities()->count())->toBe($initialCount);

    $cap = $role->capabilities()->first();

    $this->actingAs($user)->delete(route('admin.roles.capabilities.destroy', [$role, $cap]))->assertRedirect(route('admin.roles.show', $role));

    expect($role->capabilities()->where('id', $cap->id)->exists())->toBeTrue();
});

test('custom role name and description can be edited', function (): void {
    $user = createRoleTestAdmin();

    $role = Role::query()->create([
        'company_id' => $user->company_id,
        'name' => 'Editable Role',
        'code' => 'editable_role',
        'is_system' => false,
    ]);

    $this->actingAs($user)->patch(route('admin.roles.update', $role), [
        'name' => 'Updated Name',
        'description' => 'Updated description',
        'company_id' => $user->company_id,
    ])->assertRedirect(route('admin.roles.show', $role));

    $role->refresh();

    expect($role->name)->toBe('Updated Name');
    expect($role->description)->toBe('Updated description');
});

test('system role cannot be edited or deleted', function (): void {
    $user = createRoleTestAdmin();
    $role = Role::query()->where('code', 'core_admin')->firstOrFail();

    $this->actingAs($user)->patch(route('admin.roles.update', $role), [
        'name' => 'Hacked Name',
        'description' => $role->description,
        'company_id' => $role->company_id,
    ])->assertRedirect(route('admin.roles.show', $role));

    expect($role->fresh()->name)->toBe('Core Administrator');

    $this->actingAs($user)->delete(route('admin.roles.destroy', $role))->assertRedirect(route('admin.roles.show', $role));

    expect(Role::query()->where('code', 'core_admin')->exists())->toBeTrue();
});

test('custom role can be deleted', function (): void {
    $user = createRoleTestAdmin();

    $role = Role::query()->create([
        'company_id' => $user->company_id,
        'name' => 'Deletable Role',
        'code' => 'deletable_role',
        'is_system' => false,
    ]);

    $this->actingAs($user)->delete(route('admin.roles.destroy', $role))->assertRedirect(route('admin.roles.index'));

    expect(Role::query()->where('code', 'deletable_role')->exists())->toBeFalse();
});

test('custom role scope can be changed when no users assigned', function (): void {
    $user = createRoleTestAdmin();

    $role = Role::query()->create([
        'company_id' => null,
        'name' => 'Scope Test Role',
        'code' => 'scope_test',
        'is_system' => false,
    ]);

    $this->actingAs($user)->patch(route('admin.roles.update', $role), [
        'name' => $role->name,
        'description' => $role->description,
        'company_id' => Company::LICENSEE_ID,
    ])->assertRedirect(route('admin.roles.show', $role));

    expect($role->fresh()->company_id)->toBe(Company::LICENSEE_ID);
});

test('custom role scope cannot be changed when users are assigned', function (): void {
    $user = createRoleTestAdmin();

    $role = Role::query()->create([
        'company_id' => $user->company_id,
        'name' => 'Locked Scope Role',
        'code' => 'locked_scope',
        'is_system' => false,
    ]);

    PrincipalRole::query()->create([
        'company_id' => $user->company_id,
        'principal_type' => PrincipalType::HUMAN_USER->value,
        'principal_id' => $user->id,
        'role_id' => $role->id,
    ]);

    $this->actingAs($user)->patch(route('admin.roles.update', $role), [
        'name' => $role->name,
        'description' => $role->description,
        'company_id' => '',
    ])->assertRedirect(route('admin.roles.show', $role));

    expect($role->fresh()->company_id)->toBe($user->company_id);
});

test('role index shows create button for authorized users', function (): void {
    $user = createRoleTestAdmin();

    $response = $this->actingAs($user)->get(route('admin.roles.index'));

    $response->assertOk()->assertSee(__('Create Role'));
});

test('users without update capability cannot modify role capabilities', function (): void {
    $company = Company::factory()->create();
    $viewer = User::factory()->create(['company_id' => $company->id]);
    $viewerRole = Role::query()->where('code', 'user_viewer')->whereNull('company_id')->firstOrFail();

    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::HUMAN_USER->value,
        'principal_id' => $viewer->id,
        'role_id' => $viewerRole->id,
    ]);

    $targetRole = Role::query()->where('code', 'user_editor')->firstOrFail();
    $initialCount = $targetRole->capabilities()->count();

    $this->actingAs($viewer)->post(route('admin.roles.capabilities.store', $targetRole), [
        'selected_capabilities' => ['core.company.view'],
    ])->assertStatus(403);

    expect($targetRole->capabilities()->count())->toBe($initialCount);
});

test('users can be assigned to a role from role show page', function (): void {
    $admin = createRoleTestAdmin();

    $role = Role::query()->create([
        'company_id' => $admin->company_id,
        'name' => 'Assignable Role',
        'code' => 'role_for_user_assign',
        'is_system' => false,
    ]);

    $targetUser = User::factory()->create(['company_id' => $admin->company_id]);

    $this->actingAs($admin)->post(route('admin.roles.users.store', $role), [
        'selected_user_ids' => [$targetUser->id],
    ])->assertRedirect(route('admin.roles.show', $role));

    expect(
        PrincipalRole::query()
            ->where('role_id', $role->id)
            ->where('principal_id', $targetUser->id)
            ->exists()
    )->toBeTrue();
});

test('users can be removed from a role from role show page', function (): void {
    $admin = createRoleTestAdmin();

    $role = Role::query()->create([
        'company_id' => $admin->company_id,
        'name' => 'Removable Role',
        'code' => 'role_for_user_remove',
        'is_system' => false,
    ]);

    $targetUser = User::factory()->create(['company_id' => $admin->company_id]);

    $assignment = PrincipalRole::query()->create([
        'company_id' => $admin->company_id,
        'principal_type' => PrincipalType::HUMAN_USER->value,
        'principal_id' => $targetUser->id,
        'role_id' => $role->id,
    ]);

    $this->actingAs($admin)->delete(route('admin.roles.users.destroy', [$role, $assignment]))->assertRedirect(route('admin.roles.show', $role));

    expect(PrincipalRole::query()->where('id', $assignment->id)->exists())->toBeFalse();
});

test('custom global roles appear in user role assignment list', function (): void {
    $admin = createRoleTestAdmin();

    Role::query()->create([
        'company_id' => null,
        'name' => 'Custom Global Role',
        'code' => 'custom_global',
        'is_system' => false,
    ]);

    $targetUser = User::factory()->create(['company_id' => $admin->company_id]);

    $response = $this->actingAs($admin)->get(route('admin.users.show', $targetUser));

    $response->assertOk()->assertSee('Custom Global Role');
});

test('cross-company roles appear in user role assignment list', function (): void {
    $admin = createRoleTestAdmin();

    $otherCompany = Company::factory()->create();

    Role::query()->create([
        'company_id' => $otherCompany->id,
        'name' => 'Other Company Role',
        'code' => 'other_company_role',
        'is_system' => false,
    ]);

    $targetUser = User::factory()->create(['company_id' => $admin->company_id]);

    $response = $this->actingAs($admin)->get(route('admin.users.show', $targetUser));

    $response->assertOk()->assertSee('Other Company Role');
});

test('direct capabilities can be added to a user', function (): void {
    $admin = createRoleTestAdmin();
    $company = Company::factory()->create();
    $targetUser = User::factory()->create(['company_id' => $company->id]);

    $this->actingAs($admin)->post(route('admin.users.capabilities.store', $targetUser), [
        'selected_capability_keys' => ['core.company.view'],
    ])->assertRedirect(route('admin.users.show', $targetUser));

    expect(
        PrincipalCapability::query()
            ->where('principal_id', $targetUser->id)
            ->where('capability_key', 'core.company.view')
            ->where('is_allowed', true)
            ->exists()
    )->toBeTrue();
});

test('direct capabilities can be removed from a user', function (): void {
    $admin = createRoleTestAdmin();
    $company = Company::factory()->create();
    $targetUser = User::factory()->create(['company_id' => $company->id]);

    $cap = PrincipalCapability::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::HUMAN_USER->value,
        'principal_id' => $targetUser->id,
        'capability_key' => 'core.company.view',
        'is_allowed' => true,
    ]);

    $this->actingAs($admin)->delete(route('admin.users.capabilities.destroy', [$targetUser, $cap]))->assertRedirect(route('admin.users.show', $targetUser));

    expect(PrincipalCapability::query()->where('id', $cap->id)->exists())->toBeFalse();
});

test('role capability can be denied for a user', function (): void {
    $admin = createRoleTestAdmin();
    $company = Company::factory()->create();
    $targetUser = User::factory()->create(['company_id' => $company->id]);

    $role = Role::query()->where('code', 'user_viewer')->firstOrFail();
    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::HUMAN_USER->value,
        'principal_id' => $targetUser->id,
        'role_id' => $role->id,
    ]);

    $this->actingAs($admin)->post(route('admin.users.capabilities.deny', $targetUser), [
        'capability_key' => 'core.user.view',
    ])->assertRedirect(route('admin.users.show', $targetUser));

    $deny = PrincipalCapability::query()
        ->where('principal_id', $targetUser->id)
        ->where('capability_key', 'core.user.view')
        ->first();

    expect($deny)->not->toBeNull();
    expect($deny->is_allowed)->toBeFalse();
});

test('denied capability can be un-denied by removing it', function (): void {
    $admin = createRoleTestAdmin();
    $company = Company::factory()->create();
    $targetUser = User::factory()->create(['company_id' => $company->id]);

    $deny = PrincipalCapability::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::HUMAN_USER->value,
        'principal_id' => $targetUser->id,
        'capability_key' => 'core.user.view',
        'is_allowed' => false,
    ]);

    $this->actingAs($admin)->delete(route('admin.users.capabilities.destroy', [$targetUser, $deny]))->assertRedirect(route('admin.users.show', $targetUser));

    expect(PrincipalCapability::query()->where('id', $deny->id)->exists())->toBeFalse();
});
