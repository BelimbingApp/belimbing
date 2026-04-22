<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Models\RelationshipType;
use App\Modules\Core\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Seed configured system roles and their capabilities for feature tests.
 */
function setupAuthzRoles(): void
{
    $roles = config('authz.roles', []);

    foreach ($roles as $code => $roleDefinition) {
        $role = Role::query()->firstOrCreate(
            ['company_id' => null, 'code' => $code],
            [
                'name' => $roleDefinition['name'],
                'description' => $roleDefinition['description'] ?? null,
                'is_system' => true,
                'grant_all' => $roleDefinition['grant_all'] ?? false,
            ]
        );

        $now = now();

        foreach ($roleDefinition['capabilities'] ?? [] as $capabilityKey) {
            DB::table('base_authz_role_capabilities')->insertOrIgnore([
                'role_id' => $role->id,
                'capability_key' => strtolower($capabilityKey),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}

/**
 * Create a user with core_admin role for tests that need authz capabilities.
 */
function createAdminUser(): User
{
    setupAuthzRoles();

    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();

    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => $role->id,
    ]);

    return $user;
}

/**
 * Create two companies and a default relationship type for relationship tests.
 *
 * @return array{Company, Company, RelationshipType}
 */
function createCompanyRelationshipFixture(): array
{
    return [
        Company::factory()->create(),
        Company::factory()->create(),
        RelationshipType::factory()->create(),
    ];
}
