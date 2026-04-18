<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
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
    $other = User::factory()->create();

    $this->actingAs($user);

    $this->get(route('admin.users.index'))->assertOk();
    $this->get(route('admin.users.create'))->assertOk();
    $this->get(route('admin.users.show', $other))->assertOk();
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
        ->set('name', 'Jane Doe')
        ->set('email', 'jane@example.com')
        ->set('password', TEST_PASSWORD)
        ->set('passwordConfirmation', TEST_PASSWORD)
        ->call('store')
        ->assertRedirect(route('admin.users.index'));

    $user = User::query()->where('email', 'jane@example.com')->first();

    expect($user)
        ->not()->toBeNull()
        ->and($user->name)->toBe('Jane Doe')
        ->and($user->company_id)->toBeNull();
});

test('user can be created with company', function (): void {
    $actor = createAdminUser();
    $company = Company::factory()->create();
    $this->actingAs($actor);

    Livewire::test('admin.users.create')
        ->set('companyId', (string) $company->id)
        ->set('name', 'John Smith')
        ->set('email', 'john@example.com')
        ->set('password', TEST_PASSWORD)
        ->set('passwordConfirmation', TEST_PASSWORD)
        ->call('store')
        ->assertRedirect(route('admin.users.index'));

    $user = User::query()->where('email', 'john@example.com')->first();

    expect($user)
        ->not()->toBeNull()
        ->and($user->company_id)->toBe($company->id);
});

test('user fields can be inline edited from show page', function (): void {
    $actor = createAdminUser();
    $user = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.com']);
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

test('company can be changed from show page', function (): void {
    $actor = createAdminUser();
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => null]);
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
    $user = User::factory()->create();
    $this->actingAs($actor);

    Livewire::test('admin.users.show', ['user' => $user])
        ->set('password', TEST_PASSWORD_NEW)
        ->set('passwordConfirmation', TEST_PASSWORD_NEW)
        ->call('updatePassword')
        ->assertHasNoErrors();
});

test('password update requires confirmation', function (): void {
    $actor = createAdminUser();
    $user = User::factory()->create();
    $this->actingAs($actor);

    Livewire::test('admin.users.show', ['user' => $user])
        ->set('password', TEST_PASSWORD_NEW)
        ->set('passwordConfirmation', 'WrongConfirmation!')
        ->call('updatePassword')
        ->assertHasErrors(['passwordConfirmation']);
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

    $other = User::factory()->create();
    $this->actingAs($viewer);

    Livewire::test('admin.users.index')
        ->call('delete', $other->id);

    expect(User::query()->find($other->id))->not()->toBeNull();
});

test('user can be deleted from index and cannot delete self', function (): void {
    $actor = createAdminUser();
    $other = User::factory()->create();
    $this->actingAs($actor);

    Livewire::test('admin.users.index')
        ->call('delete', $other->id);

    expect(User::query()->find($other->id))->toBeNull();

    Livewire::test('admin.users.index')
        ->call('delete', $actor->id);

    expect(User::query()->find($actor->id))->not()->toBeNull();
});
