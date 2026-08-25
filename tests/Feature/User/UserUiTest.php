<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Authz\Models\RoleCapability;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

const TEST_PASSWORD = 'SecurePassword123!';
const TEST_PASSWORD_NEW = 'NewSecurePassword123!';

beforeEach(function (): void {
    setupAuthzRoles();
});

test('guests are redirected to login from user pages', function (): void {
    $user = User::factory()->create();

    $this->get(route('admin.users.index'))->assertRedirect(route('login'));
    $this->get(route('admin.users.create'))->assertRedirect(route('login'));
    $this->get(route('admin.users.show', $user))->assertRedirect(route('login'));
});

test('authenticated users with capability can view user pages', function (): void {
    $user = createAdminUser();
    $other = User::factory()->create(['company_id' => $user->company_id]);

    $this->actingAs($user);

    $this->get(route('admin.users.index'))->assertOk();
    $this->get(route('admin.users.create'))->assertOk();
    $this->get(route('admin.users.show', $other))->assertOk();
});

test('user index shows assigned roles and filters selected roles with or logic', function (): void {
    $actor = createAdminUser();
    $company = Company::factory()->create();
    $operationsRole = Role::query()->create(['company_id' => $company->id, 'name' => 'Operations Lead', 'code' => 'test_operations_lead']);
    $financeRole = Role::query()->create(['company_id' => $company->id, 'name' => 'Finance Lead', 'code' => 'test_finance_lead']);
    $peopleRole = Role::query()->create(['company_id' => $company->id, 'name' => 'People Lead', 'code' => 'test_people_lead']);
    $operationsUser = User::factory()->create(['company_id' => $company->id, 'name' => 'Role Filter Operations']);
    $financeUser = User::factory()->create(['company_id' => $company->id, 'name' => 'Role Filter Finance']);
    $peopleUser = User::factory()->create(['company_id' => $company->id, 'name' => 'Role Filter People']);

    foreach ([[$operationsUser, $operationsRole], [$financeUser, $financeRole], [$peopleUser, $peopleRole]] as [$user, $role]) {
        PrincipalRole::query()->create([
            'company_id' => $company->id,
            'principal_type' => PrincipalType::USER->value,
            'principal_id' => $user->id,
            'role_id' => $role->id,
        ]);
    }

    $this->actingAs($actor);

    Livewire::test('admin.users.index')
        ->assertSee('Roles')
        ->assertSee('Operations Lead')
        ->assertSeeHtml('id="users-role-filter-option-'.$operationsRole->id.'"')
        ->set('roleIds', [$operationsRole->id, $financeRole->id])
        ->assertSee('Role Filter Operations')
        ->assertSee('Role Filter Finance')
        ->assertDontSee('Role Filter People');
});

test('user index paginates with visible pagination controls', function (): void {
    $actor = createAdminUser();

    foreach (range(1, 30) as $number) {
        User::factory()->create([
            'company_id' => $actor->company_id,
            'name' => sprintf('Paged User %02d', $number),
            'email' => sprintf('paged-user-%02d@example.com', $number),
        ]);
    }

    $this->actingAs($actor);

    Livewire::test('admin.users.index')
        ->set('search', 'Paged User')
        ->assertSet('perPage', 25)
        ->assertSee(__('Rows per page'))
        ->assertSee(__('Showing :first to :last of :total results', [
            'first' => 1,
            'last' => 25,
            'total' => 30,
        ]))
        ->assertSee('Paged User 01')
        ->assertDontSee('Paged User 26')
        ->call('nextPage')
        ->assertSee('Paged User 26')
        ->assertSee('Paged User 30')
        ->assertDontSee('Paged User 01');
});

test('authenticated users without capability are denied', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $other = User::factory()->create();

    $this->actingAs($user);

    $this->get(route('admin.users.index'))->assertStatus(403);
    $this->get(route('admin.users.create'))->assertStatus(403);
    $this->get(route('admin.users.show', $other))->assertStatus(403);
});

test('user can be created from create page component', function (): void {
    $actor = createAdminUser();
    $this->actingAs($actor);

    Livewire::test('admin.users.create')
        ->set('companyId', null)
        ->set('name', 'Jane Doe')
        ->set('email', 'jane@example.com')
        ->set('password', TEST_PASSWORD)
        ->set('passwordConfirmation', TEST_PASSWORD)
        ->call('store');

    $user = User::query()->where('email', 'jane@example.com')->first();

    expect($user)
        ->not()->toBeNull()
        ->and($user->name)->toBe('Jane Doe')
        ->and($user->company_id)->toBeNull()
        ->and(Hash::check(TEST_PASSWORD, $user->password))->toBeTrue();
});

test('user create page defaults company to authenticated user company', function (): void {
    $actor = createAdminUser();
    $this->actingAs($actor);

    Livewire::test('admin.users.create')
        ->assertSet('companyId', $actor->company_id)
        ->set('name', 'Default Co User')
        ->set('email', 'default-co@example.com')
        ->set('password', TEST_PASSWORD)
        ->set('passwordConfirmation', TEST_PASSWORD)
        ->call('store');

    $user = User::query()->where('email', 'default-co@example.com')->first();

    expect($user)
        ->not()->toBeNull()
        ->and($user->company_id)->toBe($actor->company_id);
});

test('user can be created with company', function (): void {
    $actor = createAdminUser();
    $company = Company::factory()->create();
    $this->actingAs($actor);

    Livewire::test('admin.users.create')
        ->set('companyId', $company->id)
        ->set('name', 'John Smith')
        ->set('email', 'john@example.com')
        ->set('password', TEST_PASSWORD)
        ->set('passwordConfirmation', TEST_PASSWORD)
        ->call('store');

    $user = User::query()->where('email', 'john@example.com')->first();

    expect($user)
        ->not()->toBeNull()
        ->and($user->company_id)->toBe($company->id)
        ->and(Hash::check(TEST_PASSWORD, $user->password))->toBeTrue();
});

test('user create redirects to show page after creation', function (): void {
    $actor = createAdminUser();
    $this->actingAs($actor);

    Livewire::test('admin.users.create')
        ->set('name', 'Redirect User')
        ->set('email', 'redirect@example.com')
        ->set('password', TEST_PASSWORD)
        ->set('passwordConfirmation', TEST_PASSWORD)
        ->call('store')
        ->assertRedirect(route('admin.users.show', User::query()->where('email', 'redirect@example.com')->firstOrFail()));
});

test('user fields can be inline edited from show page', function (): void {
    $actor = createAdminUser();
    $user = User::factory()->create([
        'company_id' => $actor->company_id,
        'name' => 'Old Name',
        'email' => 'old@example.com',
    ]);
    $this->actingAs($actor);

    Livewire::test('admin.users.show', ['user' => $user])
        ->call('saveField', 'name', 'New Name');

    $user->refresh();
    expect($user->name)->toBe('New Name');

    Livewire::test('admin.users.show', ['user' => $user])
        ->call('saveField', 'email', 'new@example.com');

    $user->refresh();
    expect($user->email)->toBe('new@example.com');
});

test('email change resets email_verified_at', function (): void {
    $actor = createAdminUser();
    $user = User::factory()->create([
        'company_id' => $actor->company_id,
        'email' => 'verified@example.com',
        'email_verified_at' => now(),
    ]);
    $this->actingAs($actor);

    Livewire::test('admin.users.show', ['user' => $user])
        ->call('saveField', 'email', 'changed@example.com');

    $user->refresh();
    expect($user->email)->toBe('changed@example.com')
        ->and($user->email_verified_at)->toBeNull();
});

test('a user without a tenant-bearing company fails closed', function (): void {
    $actor = createAdminUser();
    $user = User::factory()->create(['company_id' => null]);
    $this->actingAs($actor);

    $this->get(route('admin.users.show', $user))->assertNotFound();
});

test('company can be changed from show page', function (): void {
    $actor = createAdminUser();
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $actor->company_id]);
    $this->actingAs($actor);

    Livewire::test('admin.users.show', ['user' => $user])
        ->call('saveCompany', $company->id);

    $user->refresh();
    expect($user->company_id)->toBe($company->id);

    Livewire::test('admin.users.show', ['user' => $user])
        ->call('saveCompany', null);

    $user->refresh();
    expect($user->company_id)->toBeNull();
});

test('password can be updated from show page', function (): void {
    $actor = createAdminUser();
    $user = User::factory()->create(['company_id' => $actor->company_id]);
    $this->actingAs($actor);

    Livewire::test('admin.users.show', ['user' => $user])
        ->set('password', TEST_PASSWORD_NEW)
        ->set('passwordConfirmation', TEST_PASSWORD_NEW)
        ->call('updatePassword')
        ->assertHasNoErrors();

    expect(Hash::check(TEST_PASSWORD_NEW, $user->fresh()->password))->toBeTrue();
});

test('password update requires confirmation', function (): void {
    $actor = createAdminUser();
    $user = User::factory()->create(['company_id' => $actor->company_id]);
    $this->actingAs($actor);

    Livewire::test('admin.users.show', ['user' => $user])
        ->set('password', TEST_PASSWORD_NEW)
        ->set('passwordConfirmation', 'WrongConfirmation!')
        ->call('updatePassword')
        ->assertHasErrors(['passwordConfirmation']);
});

test('user managers can delegate only roles and capabilities they hold', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->create(['company_id' => $company->id]);
    $target = User::factory()->create(['company_id' => $company->id]);
    $userManagerRole = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'User Manager',
        'code' => 'test_user_manager',
    ]);
    RoleCapability::query()->create([
        'role_id' => $userManagerRole->id,
        'capability_key' => 'admin.user.update',
    ]);
    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $actor->id,
        'role_id' => $userManagerRole->id,
    ]);
    PrincipalCapability::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $actor->id,
        'capability_key' => 'admin.company.view',
        'is_allowed' => true,
    ]);
    $coreAdminRole = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();

    $this->actingAs($actor);
    app(TenantContext::class)->set((int) $company->tenant_id);

    Livewire::test('admin.users.show', ['user' => $target])
        ->set('selectedRoleIds', [$userManagerRole->id, $coreAdminRole->id])
        ->call('assignRoles')
        ->set('selectedCapabilityKeys', ['admin.company.view', 'admin.authz.role.update'])
        ->call('addCapabilities');

    expect(PrincipalRole::query()
        ->where('principal_id', $target->id)
        ->whereIn('role_id', [$userManagerRole->id, $coreAdminRole->id])
        ->exists())->toBeFalse()
        ->and(PrincipalCapability::query()
            ->where('principal_id', $target->id)
            ->whereIn('capability_key', ['admin.company.view', 'admin.authz.role.update'])
            ->exists())->toBeFalse();

    $component = Livewire::test('admin.users.show', ['user' => $target]);

    expect($component->viewData('availableRoles')->modelKeys())
        ->toContain($userManagerRole->id)
        ->not->toContain($coreAdminRole->id)
        ->and(collect($component->viewData('availableCapabilities'))->flatten()->all())
        ->toContain('admin.company.view')
        ->not->toContain('admin.authz.role.update');

    $component
        ->set('selectedRoleIds', [$userManagerRole->id])
        ->call('assignRoles')
        ->set('selectedCapabilityKeys', ['admin.company.view'])
        ->call('addCapabilities');

    expect(PrincipalRole::query()
        ->where('principal_id', $target->id)
        ->where('role_id', $userManagerRole->id)
        ->exists())->toBeTrue()
        ->and(PrincipalCapability::query()
            ->where('principal_id', $target->id)
            ->where('capability_key', 'admin.company.view')
            ->where('is_allowed', true)
            ->exists())->toBeTrue();
});

test('user without delete capability cannot delete users', function (): void {
    $company = Company::factory()->create();
    $viewer = User::factory()->create(['company_id' => $company->id]);
    $viewerRole = Role::query()->where('code', 'user_viewer')->whereNull('company_id')->firstOrFail();

    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $viewer->id,
        'role_id' => $viewerRole->id,
    ]);

    $other = User::factory()->create(['company_id' => $company->id]);
    $this->actingAs($viewer);
    app(TenantContext::class)->set((int) $company->tenant_id);

    Livewire::test('admin.users.index')
        ->call('delete', $other->id);

    expect(User::query()->find($other->id))->not()->toBeNull();
});

test('user can be deleted from index and cannot delete self', function (): void {
    $actor = createAdminUser();
    $other = User::factory()->create(['company_id' => $actor->company_id]);
    $this->actingAs($actor);

    Livewire::test('admin.users.index')
        ->call('delete', $other->id);

    expect(User::query()->find($other->id))->toBeNull();

    Livewire::test('admin.users.index')
        ->call('delete', $actor->id);

    expect(User::query()->find($actor->id))->not()->toBeNull();
});
