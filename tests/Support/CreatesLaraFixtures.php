<?php

namespace Tests\Support;

use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;

trait CreatesLaraFixtures
{
    /**
     * @return array{company: Company, employee: Employee, user: User}
     */
    public function createLaraFixture(
        array $companyOverrides = [],
        array $employeeOverrides = [],
        array $userOverrides = [],
    ): array {
        $company = Company::factory()->create($companyOverrides);
        $employee = Employee::factory()->create([
            'company_id' => $company->id,
            'status' => 'active',
            ...$employeeOverrides,
        ]);

        $user = User::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            ...$userOverrides,
        ]);

        return [
            'company' => $company,
            'employee' => $employee,
            'user' => $user,
        ];
    }
}
