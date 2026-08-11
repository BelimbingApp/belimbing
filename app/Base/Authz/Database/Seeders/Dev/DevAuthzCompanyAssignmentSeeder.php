<?php

namespace App\Base\Authz\Database\Seeders\Dev;

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Database\Seeders\DevSeeder;
use App\Core\User\Database\Seeders\Dev\DevUserSeeder;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Collection;

class DevAuthzCompanyAssignmentSeeder extends DevSeeder
{
    protected array $dependencies = [
        DevUserSeeder::class,
    ];

    /**
     * Seed the database.
     *
     * 1. Grants the platform operator's admin user all system roles for full access.
     * 2. Assigns the first user in each remaining company to core_admin for basic testing.
     */
    protected function seed(): void
    {
        $systemRoles = Role::query()
            ->whereNull('company_id')
            ->where('is_system', true)
            ->get();

        if ($systemRoles->isEmpty()) {
            return;
        }

        $this->grantDevAdminFullAccess($systemRoles);
        $this->assignCoreAdminPerCompany($systemRoles);
    }

    /**
     * Grant core_admin (grant_all) role to the platform operator's admin user.
     *
     * @param  Collection<int, Role>  $systemRoles
     */
    private function grantDevAdminFullAccess($systemRoles): void
    {
        $operatorCompany = $this->operatorPrimaryCompany();

        if ($operatorCompany === null) {
            return;
        }

        $adminUser = $operatorCompany->resolveAdminUser();

        if ($adminUser === null) {
            return;
        }

        $coreAdminRole = $systemRoles->firstWhere('code', 'core_admin');

        if ($coreAdminRole === null) {
            return;
        }

        PrincipalRole::query()->firstOrCreate([
            'company_id' => $adminUser->company_id,
            'principal_type' => PrincipalType::USER->value,
            'principal_id' => $adminUser->id,
            'role_id' => $coreAdminRole->id,
        ]);
    }

    /**
     * Assign core_admin to the first user in each company (excluding the dev admin's company).
     *
     * @param  Collection<int, Role>  $systemRoles
     */
    private function assignCoreAdminPerCompany($systemRoles): void
    {
        $coreAdminRole = $systemRoles->firstWhere('code', 'core_admin');

        if ($coreAdminRole === null) {
            return;
        }

        $operatorCompany = $this->operatorPrimaryCompany();

        if ($operatorCompany === null) {
            return;
        }

        $users = User::query()
            ->whereNotNull('company_id')
            ->where('company_id', '!=', $operatorCompany->id)
            ->orderBy('id')
            ->get()
            ->groupBy('company_id')
            ->map(fn ($companyUsers) => $companyUsers->first())
            ->filter();

        foreach ($users as $user) {
            PrincipalRole::query()->firstOrCreate([
                'company_id' => $user->company_id,
                'principal_type' => PrincipalType::USER->value,
                'principal_id' => $user->id,
                'role_id' => $coreAdminRole->id,
            ]);
        }
    }
}
