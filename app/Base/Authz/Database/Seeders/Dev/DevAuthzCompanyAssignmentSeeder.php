<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Authz\Database\Seeders\Dev;

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Database\Seeders\DevSeeder;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;

class DevAuthzCompanyAssignmentSeeder extends DevSeeder
{
    protected array $dependencies = [
        \App\Modules\Core\User\Database\Seeders\Dev\DevUserSeeder::class,
    ];

    /**
     * Seed the database.
     *
     * 1. Grants the licensee admin user all system roles for full access.
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
     * Grant core_admin (grant_all) role to the licensee admin user.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Role>  $systemRoles
     */
    private function grantDevAdminFullAccess($systemRoles): void
    {
        $adminUser = User::query()
            ->where('company_id', Company::LICENSEE_ID)
            ->first();

        if ($adminUser === null) {
            return;
        }

        $coreAdminRole = $systemRoles->firstWhere('code', 'core_admin');

        if ($coreAdminRole === null) {
            return;
        }

        PrincipalRole::query()->firstOrCreate([
            'company_id' => $adminUser->company_id,
            'principal_type' => PrincipalType::HUMAN_USER->value,
            'principal_id' => $adminUser->id,
            'role_id' => $coreAdminRole->id,
        ]);
    }

    /**
     * Assign core_admin to the first user in each company (excluding the dev admin's company).
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Role>  $systemRoles
     */
    private function assignCoreAdminPerCompany($systemRoles): void
    {
        $coreAdminRole = $systemRoles->firstWhere('code', 'core_admin');

        if ($coreAdminRole === null) {
            return;
        }

        $users = User::query()
            ->whereNotNull('company_id')
            ->where('company_id', '!=', Company::LICENSEE_ID)
            ->orderBy('id')
            ->get()
            ->groupBy('company_id')
            ->map(fn ($companyUsers) => $companyUsers->first())
            ->filter();

        foreach ($users as $user) {
            PrincipalRole::query()->firstOrCreate([
                'company_id' => $user->company_id,
                'principal_type' => PrincipalType::HUMAN_USER->value,
                'principal_id' => $user->id,
                'role_id' => $coreAdminRole->id,
            ]);
        }
    }
}
