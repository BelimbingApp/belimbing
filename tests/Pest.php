<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Create a user with core_admin role for tests that need authz capabilities.
 */
function createAdminUser(): User
{
    $roleDefinition = config('authz.roles.core_admin', []);
    $role = Role::query()->updateOrCreate(
        ['company_id' => null, 'code' => 'core_admin'],
        [
            'name' => $roleDefinition['name'] ?? 'Core Administrator',
            'description' => $roleDefinition['description'] ?? 'System role with all capabilities.',
            'is_system' => true,
            'grant_all' => $roleDefinition['grant_all'] ?? true,
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

    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::HUMAN_USER->value,
        'principal_id' => $user->id,
        'role_id' => $role->id,
    ]);

    return $user;
}
