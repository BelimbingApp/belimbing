<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Authz\Services\ImpersonationManager;
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

it('allows admin to start impersonation', function (): void {
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id]);
    $target = User::factory()->create(['company_id' => $company->id]);

    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();

    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::HUMAN_USER->value,
        'principal_id' => $admin->id,
        'role_id' => $role->id,
    ]);

    $response = $this->actingAs($admin)->post(route('admin.impersonate.start', $target));

    $response->assertRedirect(route('dashboard'));
    expect(session('impersonation.original_user_id'))->toBe($admin->id);
    expect(auth()->id())->toBe($target->id);
});

it('denies impersonation without capability', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    $target = User::factory()->create(['company_id' => $company->id]);

    $response = $this->actingAs($user)->post(route('admin.impersonate.start', $target));

    $response->assertStatus(403);
    expect(session('impersonation.original_user_id'))->toBeNull();
});

it('stops impersonation and restores original user', function (): void {
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id]);
    $target = User::factory()->create(['company_id' => $company->id]);

    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();

    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::HUMAN_USER->value,
        'principal_id' => $admin->id,
        'role_id' => $role->id,
    ]);

    $this->actingAs($admin)->post(route('admin.impersonate.start', $target));

    $response = $this->post(route('admin.impersonate.stop'));

    $response->assertRedirect(route('dashboard'));
    expect(session('impersonation.original_user_id'))->toBeNull();
    expect(auth()->id())->toBe($admin->id);
});

it('prevents impersonating yourself', function (): void {
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id]);

    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();

    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::HUMAN_USER->value,
        'principal_id' => $admin->id,
        'role_id' => $role->id,
    ]);

    $this->actingAs($admin);

    $manager = app(ImpersonationManager::class);

    expect(fn () => $manager->start($admin, $admin))
        ->toThrow(InvalidArgumentException::class, 'Cannot impersonate yourself.');
});

it('reports impersonation state correctly', function (): void {
    $manager = app(ImpersonationManager::class);

    expect($manager->isImpersonating())->toBeFalse();
    expect($manager->getImpersonatorId())->toBeNull();
    expect($manager->getImpersonatorName())->toBeNull();

    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id, 'name' => 'Admin User']);
    $target = User::factory()->create(['company_id' => $company->id]);

    $this->actingAs($admin);

    $manager->start($admin, $target);

    expect($manager->isImpersonating())->toBeTrue();
    expect($manager->getImpersonatorId())->toBe($admin->id);
    expect($manager->getImpersonatorName())->toBe('Admin User');
});

it('denies impersonation for unauthenticated users', function (): void {
    $target = User::factory()->create();

    $response = $this->post(route('admin.impersonate.start', $target));

    $response->assertRedirect(route('login'));
});
