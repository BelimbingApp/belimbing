<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

const CORE_ADMINISTRATOR_NAME = 'Core Administrator';

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
    $this->actingAs($user);

    Livewire::test('admin.roles.index')
        ->assertSee(CORE_ADMINISTRATOR_NAME);

    Livewire::test('admin.roles.index')
        ->set('search', 'user viewer')
        ->assertSee('User Viewer')
        ->assertDontSee(CORE_ADMINISTRATOR_NAME);

    Livewire::test('admin.roles.index')
        ->set('search', 'user editor')
        ->assertSee('User Editor')
        ->assertDontSee(CORE_ADMINISTRATOR_NAME);
});

test('role show displays role details and capabilities', function (): void {
    $user = createRoleTestAdmin();
    $role = Role::query()->where('code', 'user_viewer')->firstOrFail();

    $this->actingAs($user);

    Livewire::test('admin.roles.show', ['role' => $role])
        ->assertSee('User Viewer')
        ->assertSee('user_viewer')
        ->assertSee('core.user.view');
});

test('capabilities can be assigned to a custom role', function (): void {
    $user = createRoleTestAdmin();
    $this->actingAs($user);

    $role = Role::query()->create([
        'company_id' => $user->company_id,
        'name' => 'Assignable Role',
        'code' => 'assignable_role',
        'is_system' => false,
    ]);

    Livewire::test('admin.roles.show', ['role' => $role])
        ->set('selectedCapabilities', ['core.user.create'])
        ->call('assignCapabilities');

    expect($role->capabilities()->count())->toBe(1);
    expect(
        $role->capabilities()->where('capability_key', 'core.user.create')->exists()
    )->toBeTrue();
});

test('capabilities can be removed from a custom role', function (): void {
    $user = createRoleTestAdmin();
    $this->actingAs($user);

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

    Livewire::test('admin.roles.show', ['role' => $role])
        ->call('removeCapability', $cap->id);

    expect(
        $role->capabilities()->where('capability_key', 'core.user.view')->exists()
    )->toBeFalse();
});

test('custom role can be created', function (): void {
    $user = createRoleTestAdmin();
    $this->actingAs($user);

    Livewire::test('admin.roles.create')
        ->set('name', 'Test Custom Role')
        ->set('code', 'test_custom')
        ->set('description', 'A test custom role')
        ->set('company_id', $user->company_id)
        ->call('createRole')
        ->assertRedirect();

    $role = Role::query()->where('code', 'test_custom')->first();

    expect($role)->not->toBeNull();
    expect($role->name)->toBe('Test Custom Role');
    expect($role->is_system)->toBeFalse();
    expect($role->company_id)->toBe($user->company_id);
});

test('duplicate role code in same scope returns validation error', function (): void {
    $user = createRoleTestAdmin();
    $this->actingAs($user);

    Role::query()->create([
        'company_id' => $user->company_id,
        'name' => 'Existing Role',
        'code' => 'duplicate_code',
        'is_system' => false,
    ]);

    Livewire::test('admin.roles.create')
        ->set('name', 'Another Role')
        ->set('code', 'duplicate_code')
        ->set('company_id', $user->company_id)
        ->call('createRole')
        ->assertHasErrors(['code']);
});

test('same role code in different company scope is allowed', function (): void {
    $user = createRoleTestAdmin();
    $this->actingAs($user);

    $otherCompany = Company::factory()->create();

    Role::query()->create([
        'company_id' => $otherCompany->id,
        'name' => 'Existing Role',
        'code' => 'shared_code',
        'is_system' => false,
    ]);

    Livewire::test('admin.roles.create')
        ->set('name', 'My Role')
        ->set('code', 'shared_code')
        ->set('company_id', $user->company_id)
        ->call('createRole')
        ->assertRedirect();

    expect(Role::query()->where('code', 'shared_code')->count())->toBe(2);
});

test('system role capabilities cannot be modified via UI', function (): void {
    $user = createRoleTestAdmin();
    $role = Role::query()->where('code', 'user_viewer')->firstOrFail();
    $this->actingAs($user);

    $initialCount = $role->capabilities()->count();

    Livewire::test('admin.roles.show', ['role' => $role])
        ->set('selectedCapabilities', ['core.geonames.view'])
        ->call('assignCapabilities');

    expect($role->capabilities()->count())->toBe($initialCount);

    $cap = $role->capabilities()->first();

    Livewire::test('admin.roles.show', ['role' => $role])
        ->call('removeCapability', $cap->id);

    expect($role->capabilities()->where('id', $cap->id)->exists())->toBeTrue();
});

test('custom role name and description can be edited', function (): void {
    $user = createRoleTestAdmin();
    $this->actingAs($user);

    $role = Role::query()->create([
        'company_id' => $user->company_id,
        'name' => 'Editable Role',
        'code' => 'editable_role',
        'is_system' => false,
    ]);

    Livewire::test('admin.roles.show', ['role' => $role])
        ->call('saveField', 'name', 'Updated Name')
        ->call('saveField', 'description', 'Updated description');

    $role->refresh();

    expect($role->name)->toBe('Updated Name');
    expect($role->description)->toBe('Updated description');
});

test('system role cannot be edited or deleted', function (): void {
    $user = createRoleTestAdmin();
    $role = Role::query()->where('code', 'core_admin')->firstOrFail();
    $this->actingAs($user);

    Livewire::test('admin.roles.show', ['role' => $role])
        ->call('saveField', 'name', 'Hacked Name');

    expect($role->fresh()->name)->toBe('Core Administrator');

    Livewire::test('admin.roles.show', ['role' => $role])
        ->call('deleteRole');

    expect(Role::query()->where('code', 'core_admin')->exists())->toBeTrue();
});

test('custom role can be deleted', function (): void {
    $user = createRoleTestAdmin();
    $this->actingAs($user);

    $role = Role::query()->create([
        'company_id' => $user->company_id,
        'name' => 'Deletable Role',
        'code' => 'deletable_role',
        'is_system' => false,
    ]);

    Livewire::test('admin.roles.show', ['role' => $role])
        ->call('deleteRole')
        ->assertRedirect(route('admin.roles.index'));

    expect(Role::query()->where('code', 'deletable_role')->exists())->toBeFalse();
});

test('custom role scope can be changed when no users assigned', function (): void {
    $user = createRoleTestAdmin();
    $this->actingAs($user);

    $role = Role::query()->create([
        'company_id' => null,
        'name' => 'Scope Test Role',
        'code' => 'scope_test',
        'is_system' => false,
    ]);

    Livewire::test('admin.roles.show', ['role' => $role])
        ->call('saveScope', (string) Company::LICENSEE_ID);

    expect($role->fresh()->company_id)->toBe(Company::LICENSEE_ID);
});

test('custom role scope cannot be changed when users are assigned', function (): void {
    $user = createRoleTestAdmin();
    $this->actingAs($user);

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

    Livewire::test('admin.roles.show', ['role' => $role])
        ->call('saveScope', '');

    expect($role->fresh()->company_id)->toBe($user->company_id);
});

test('role index shows create button for authorized users', function (): void {
    $user = createRoleTestAdmin();
    $this->actingAs($user);

    Livewire::test('admin.roles.index')
        ->assertSee(__('Create Role'));
});

test('users without update capability cannot modify role capabilities', function (): void {
    $company = Company::factory()->create();
    $viewer = User::factory()->create(['company_id' => $company->id]);
    $viewerRole = Role::query()->where('code', 'user_viewer')->whereNull('company_id')->firstOrFail();

    // Give viewer only user_viewer role (has core.user.list + core.user.view, not admin.role.update)
    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::HUMAN_USER->value,
        'principal_id' => $viewer->id,
        'role_id' => $viewerRole->id,
    ]);

    $targetRole = Role::query()->where('code', 'user_editor')->firstOrFail();
    $initialCount = $targetRole->capabilities()->count();

    $this->actingAs($viewer);

    Livewire::test('admin.roles.show', ['role' => $targetRole])
        ->set('selectedCapabilities', ['core.company.view'])
        ->call('assignCapabilities');

    expect($targetRole->capabilities()->count())->toBe($initialCount);
});

test('users can be assigned to a role from role show page', function (): void {
    $admin = createRoleTestAdmin();
    $this->actingAs($admin);

    $role = Role::query()->create([
        'company_id' => $admin->company_id,
        'name' => 'Assignable Role',
        'code' => 'role_for_user_assign',
        'is_system' => false,
    ]);

    $targetUser = User::factory()->create(['company_id' => $admin->company_id]);

    Livewire::test('admin.roles.show', ['role' => $role])
        ->set('selectedUserIds', [(string) $targetUser->id])
        ->call('assignUsers');

    expect(
        PrincipalRole::query()
            ->where('role_id', $role->id)
            ->where('principal_id', $targetUser->id)
            ->exists()
    )->toBeTrue();
});

test('users can be removed from a role from role show page', function (): void {
    $admin = createRoleTestAdmin();
    $this->actingAs($admin);

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

    Livewire::test('admin.roles.show', ['role' => $role])
        ->call('removeUser', $assignment->id);

    expect(PrincipalRole::query()->where('id', $assignment->id)->exists())->toBeFalse();
});

test('custom global roles appear in user role assignment list', function (): void {
    $admin = createRoleTestAdmin();
    $this->actingAs($admin);

    $customGlobalRole = Role::query()->create([
        'company_id' => null,
        'name' => 'Custom Global Role',
        'code' => 'custom_global',
        'is_system' => false,
    ]);

    $targetUser = User::factory()->create(['company_id' => $admin->company_id]);

    Livewire::test('users.show', ['user' => $targetUser])
        ->assertSee('Custom Global Role');
});

test('cross-company roles appear in user role assignment list', function (): void {
    $admin = createRoleTestAdmin();
    $this->actingAs($admin);

    $otherCompany = Company::factory()->create();

    $crossCompanyRole = Role::query()->create([
        'company_id' => $otherCompany->id,
        'name' => 'Other Company Role',
        'code' => 'other_company_role',
        'is_system' => false,
    ]);

    $targetUser = User::factory()->create(['company_id' => $admin->company_id]);

    Livewire::test('users.show', ['user' => $targetUser])
        ->assertSee('Other Company Role');
});

test('direct capabilities can be added to a user', function (): void {
    $admin = createRoleTestAdmin();
    $company = Company::factory()->create();
    $targetUser = User::factory()->create(['company_id' => $company->id]);

    $this->actingAs($admin);

    Livewire::test('users.show', ['user' => $targetUser])
        ->set('selectedCapabilityKeys', ['core.company.view'])
        ->call('addCapabilities');

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

    $this->actingAs($admin);

    Livewire::test('users.show', ['user' => $targetUser])
        ->call('removeCapability', $cap->id);

    expect(PrincipalCapability::query()->where('id', $cap->id)->exists())->toBeFalse();
});

test('role capability can be denied for a user', function (): void {
    $admin = createRoleTestAdmin();
    $company = Company::factory()->create();
    $targetUser = User::factory()->create(['company_id' => $company->id]);

    // Give user a role so they have role-based capabilities
    $role = Role::query()->where('code', 'user_viewer')->firstOrFail();
    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::HUMAN_USER->value,
        'principal_id' => $targetUser->id,
        'role_id' => $role->id,
    ]);

    $this->actingAs($admin);

    Livewire::test('users.show', ['user' => $targetUser])
        ->call('denyCapability', 'core.user.view');

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

    $this->actingAs($admin);

    Livewire::test('users.show', ['user' => $targetUser])
        ->call('removeCapability', $deny->id);

    expect(PrincipalCapability::query()->where('id', $deny->id)->exists())->toBeFalse();
});
