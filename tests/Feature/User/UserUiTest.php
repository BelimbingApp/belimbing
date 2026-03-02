<?php

use App\Base\Authz\Enums\PrincipalType;
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

    $response = $this->actingAs($actor)->post(route('admin.users.store'), [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'SecurePassword123!',
        'password_confirmation' => 'SecurePassword123!',
    ]);

    $response->assertRedirect(route('admin.users.index'));

    $user = User::query()->where('email', 'jane@example.com')->first();

    expect($user)
        ->not()->toBeNull()
        ->and($user->name)->toBe('Jane Doe')
        ->and($user->company_id)->toBeNull();
});

test('user can be created with company', function (): void {
    $actor = createAdminUser();
    $company = Company::factory()->create();

    $response = $this->actingAs($actor)->post(route('admin.users.store'), [
        'company_id' => $company->id,
        'name' => 'John Smith',
        'email' => 'john@example.com',
        'password' => 'SecurePassword123!',
        'password_confirmation' => 'SecurePassword123!',
    ]);

    $response->assertRedirect(route('admin.users.index'));

    $user = User::query()->where('email', 'john@example.com')->first();

    expect($user)
        ->not()->toBeNull()
        ->and($user->company_id)->toBe($company->id);
});

test('user fields can be inline edited from show page', function (): void {
    $actor = createAdminUser();
    $user = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.com']);

    $this->actingAs($actor)->patch(route('admin.users.update-field', $user), [
        'field' => 'name',
        'value' => 'New Name',
    ])->assertRedirect();

    $user->refresh();
    expect($user->name)->toBe('New Name');

    $this->actingAs($actor)->patch(route('admin.users.update-field', $user), [
        'field' => 'email',
        'value' => 'new@example.com',
    ])->assertRedirect();

    $user->refresh();
    expect($user->email)->toBe('new@example.com');
});

test('email change resets email_verified_at', function (): void {
    $actor = createAdminUser();
    $user = User::factory()->create([
        'email' => 'verified@example.com',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($actor)->patch(route('admin.users.update-field', $user), [
        'field' => 'email',
        'value' => 'changed@example.com',
    ])->assertRedirect();

    $user->refresh();
    expect($user->email)->toBe('changed@example.com')
        ->and($user->email_verified_at)->toBeNull();
});

test('company can be changed from show page', function (): void {
    $actor = createAdminUser();
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => null]);

    $this->actingAs($actor)->patch(route('admin.users.update-company', $user), [
        'company_id' => $company->id,
    ])->assertRedirect();

    $user->refresh();
    expect($user->company_id)->toBe($company->id);

    $this->actingAs($actor)->patch(route('admin.users.update-company', $user), [
        'company_id' => '',
    ])->assertRedirect();

    $user->refresh();
    expect($user->company_id)->toBeNull();
});

test('password can be updated from show page', function (): void {
    $actor = createAdminUser();
    $user = User::factory()->create();

    $response = $this->actingAs($actor)->patch(route('admin.users.update-password', $user), [
        'password' => 'NewSecurePassword123!',
        'password_confirmation' => 'NewSecurePassword123!',
    ]);

    $response->assertSessionHasNoErrors();
});

test('password update requires confirmation', function (): void {
    $actor = createAdminUser();
    $user = User::factory()->create();

    $response = $this->actingAs($actor)
        ->from(route('admin.users.show', $user))
        ->patch(route('admin.users.update-password', $user), [
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'WrongConfirmation!',
        ]);

    $response->assertSessionHasErrors(['password']);
});

test('user without delete capability cannot delete users', function (): void {
    $company = Company::factory()->create();
    $viewer = User::factory()->create(['company_id' => $company->id]);
    $viewerRole = Role::query()->where('code', 'user_viewer')->whereNull('company_id')->firstOrFail();

    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::HUMAN_USER->value,
        'principal_id' => $viewer->id,
        'role_id' => $viewerRole->id,
    ]);

    $other = User::factory()->create();

    $this->actingAs($viewer)->delete(route('admin.users.destroy', $other))->assertStatus(403);

    expect(User::query()->find($other->id))->not()->toBeNull();
});

test('user can be deleted from index and cannot delete self', function (): void {
    $actor = createAdminUser();
    $other = User::factory()->create();

    $this->actingAs($actor)->delete(route('admin.users.destroy', $other))->assertRedirect(route('admin.users.index'));

    expect(User::query()->find($other->id))->toBeNull();

    $this->actingAs($actor)->delete(route('admin.users.destroy', $actor))->assertRedirect(route('admin.users.index'));

    expect(User::query()->find($actor->id))->not()->toBeNull();
});
