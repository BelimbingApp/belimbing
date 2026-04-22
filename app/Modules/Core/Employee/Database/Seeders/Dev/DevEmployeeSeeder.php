<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Employee\Database\Seeders\Dev;

use App\Base\Database\Seeders\DevSeeder;
use App\Modules\Core\Company\Database\Seeders\Dev\DevDepartmentSeeder;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Models\Department;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;

class DevEmployeeSeeder extends DevSeeder
{
    protected array $dependencies = [
        DevDepartmentSeeder::class,
    ];

    /**
     * Seed the database.
     *
     * Creates realistic employee records for existing companies with
     * department placements and supervisor hierarchies. Also seeds a
     * licensee employee from the admin user created by framework primitives.
     */
    protected function seed(): void
    {
        $licensee = Company::query()->find(Company::LICENSEE_ID);
        if ($licensee) {
            $this->seedLicenseeEmployee($licensee);
        }

        $stellar = Company::query()->where('name', 'Stellar Industries Sdn Bhd')->first();
        $nusantara = Company::query()->where('name', 'Nusantara Trading Co')->first();
        $borneo = Company::query()->where('name', 'Borneo Logistics')->first();

        if ($stellar) {
            $this->seedStellarEmployees($stellar);
        }

        if ($nusantara) {
            $this->seedNusantaraEmployees($nusantara);
        }

        if ($borneo) {
            $this->seedBorneoEmployees($borneo);
        }
    }

    /**
     * Seed licensee company employee and agents (tech company building Belimbing).
     *
     * @param  Company  $company  The licensee company (Company::LICENSEE_ID)
     */
    protected function seedLicenseeEmployee(Company $company): void
    {
        $admin = User::query()
            ->where('company_id', Company::LICENSEE_ID)
            ->first();

        if ($admin === null) {
            return;
        }

        $fullName = $admin->name;
        $email = $admin->email;
        $shortName = str_contains($fullName, ' ') ? explode(' ', $fullName, 2)[0] : $fullName;

        $deptMap = $this->getDepartmentMap($company);
        $execDeptId = $deptMap['exec'] ?? null;
        $itDeptId = $deptMap['it'] ?? null;

        $employee = $this->createEmployee($company, [
            'employee_number' => 'LIC-001',
            'full_name' => $fullName,
            'short_name' => $shortName,
            'designation' => 'Administrator',
            'employee_type' => 'full_time',
            'email' => $email,
            'status' => 'active',
            'employment_start' => now()->toDateString(),
            'department_id' => $execDeptId,
        ]);

        // Link admin user to their employee record. This lives in the dev
        // seeder — not in framework primitives — because production admins
        // are not necessarily employees of the licensee company (e.g. an
        // indie developer who is the sole admin but has no employee record).
        if ($employee) {
            $admin->update(['employee_id' => $employee->id]);
        }

        $agents = [
            [
                'employee_number' => 'LIC-002',
                'full_name' => 'Aiman Rahman',
                'short_name' => 'Aiman',
                'designation' => 'Lead Developer',
                'job_description' => 'Leads Belimbing technical direction, designs core framework architecture, and delivers full-stack features across backend and frontend modules.',
                'department_id' => $itDeptId,
            ],
            [
                'employee_number' => 'LIC-003',
                'full_name' => 'Sofia Lim',
                'short_name' => 'Sofia',
                'designation' => 'UX/UI Designer',
                'job_description' => 'Shapes Belimbing product experience through user flows, design system components, accessible UI patterns, and consistent visual standards across modules.',
                'department_id' => $itDeptId,
            ],
            [
                'employee_number' => 'LIC-004',
                'full_name' => 'Daniel Khoo',
                'short_name' => 'Daniel',
                'designation' => 'Product Manager',
                'job_description' => 'Defines Belimbing roadmap and release priorities, translates framework vision into clear requirements, and aligns delivery scope with adopter needs.',
                'department_id' => $itDeptId,
            ],
        ];

        foreach ($agents as $def) {
            $deptId = $def['department_id'];
            unset($def['department_id']);
            $this->createEmployee($company, array_merge($def, [
                'employee_type' => 'agent',
                'status' => 'active',
                'employment_start' => now()->toDateString(),
                'department_id' => $deptId,
                'supervisor_id' => $admin?->id,
            ]));
        }
    }

    /**
     * Seed employees for Stellar Industries — largest company with full org structure.
     *
     * @param  Company  $company  The Stellar Industries company
     */
    protected function seedStellarEmployees(Company $company): void
    {
        $deptMap = $this->getDepartmentMap($company);

        $ceo = $this->createEmployee($company, [
            'employee_number' => 'STL-001',
            'full_name' => 'Lim Wei Jie',
            'short_name' => 'Wei Jie',
            'designation' => 'Chief Executive Officer',
            'employee_type' => 'full_time',
            'email' => 'weijie.lim@stellarindustries.com.my',
            'mobile_number' => '+60 12-345 6789',
            'status' => 'active',
            'employment_start' => '2019-06-01',
            'department_id' => $deptMap['exec'] ?? null,
        ]);

        $cfo = $this->createEmployee($company, [
            'employee_number' => 'STL-002',
            'full_name' => 'Tan Siew Mei',
            'short_name' => 'Siew Mei',
            'designation' => 'Chief Financial Officer',
            'employee_type' => 'full_time',
            'email' => 'siewmei.tan@stellarindustries.com.my',
            'mobile_number' => '+60 12-456 7890',
            'status' => 'active',
            'employment_start' => '2019-07-15',
            'department_id' => $deptMap['finance'] ?? null,
            'supervisor_id' => $ceo?->id,
        ]);

        $opsHead = $this->createEmployee($company, [
            'employee_number' => 'STL-003',
            'full_name' => 'Ahmad bin Ismail',
            'short_name' => 'Ahmad',
            'designation' => 'Head of Operations',
            'employee_type' => 'full_time',
            'email' => 'ahmad.ismail@stellarindustries.com.my',
            'mobile_number' => '+60 13-567 8901',
            'status' => 'active',
            'employment_start' => '2019-08-01',
            'department_id' => $deptMap['operations'] ?? null,
            'supervisor_id' => $ceo?->id,
        ]);

        $hrHead = $this->createEmployee($company, [
            'employee_number' => 'STL-004',
            'full_name' => 'Priya Devi a/p Rajan',
            'short_name' => 'Priya',
            'designation' => 'HR Manager',
            'employee_type' => 'full_time',
            'email' => 'priya.rajan@stellarindustries.com.my',
            'mobile_number' => '+60 16-678 9012',
            'status' => 'active',
            'employment_start' => '2020-01-06',
            'department_id' => $deptMap['hr'] ?? null,
            'supervisor_id' => $ceo?->id,
        ]);

        $itLead = $this->createEmployee($company, [
            'employee_number' => 'STL-005',
            'full_name' => 'Ong Kah Heng',
            'short_name' => 'Kah Heng',
            'designation' => 'IT Manager',
            'employee_type' => 'full_time',
            'email' => 'kahheng.ong@stellarindustries.com.my',
            'mobile_number' => '+60 17-789 0123',
            'status' => 'active',
            'employment_start' => '2020-03-16',
            'department_id' => $deptMap['it'] ?? null,
            'supervisor_id' => $ceo?->id,
        ]);

        $this->createEmployee($company, [
            'employee_number' => 'STL-006',
            'full_name' => 'Nurul Aisyah binti Mohd',
            'short_name' => 'Aisyah',
            'designation' => 'Accountant',
            'employee_type' => 'full_time',
            'email' => 'aisyah.mohd@stellarindustries.com.my',
            'status' => 'active',
            'employment_start' => '2021-04-12',
            'department_id' => $deptMap['finance'] ?? null,
            'supervisor_id' => $cfo?->id,
        ]);

        $this->createEmployee($company, [
            'employee_number' => 'STL-007',
            'full_name' => 'Wong Mei Ling',
            'short_name' => 'Mei Ling',
            'designation' => 'Sales Executive',
            'employee_type' => 'full_time',
            'email' => 'meiling.wong@stellarindustries.com.my',
            'mobile_number' => '+60 11-890 1234',
            'status' => 'active',
            'employment_start' => '2021-06-01',
            'department_id' => $deptMap['sales'] ?? null,
            'supervisor_id' => $opsHead?->id,
        ]);

        $this->createEmployee($company, [
            'employee_number' => 'STL-008',
            'full_name' => 'Raj Kumar a/l Subramaniam',
            'short_name' => 'Raj',
            'designation' => 'Production Supervisor',
            'employee_type' => 'full_time',
            'email' => 'raj.kumar@stellarindustries.com.my',
            'mobile_number' => '+60 19-901 2345',
            'status' => 'active',
            'employment_start' => '2020-09-01',
            'department_id' => $deptMap['production'] ?? null,
            'supervisor_id' => $opsHead?->id,
        ]);

        $this->createEmployee($company, [
            'employee_number' => 'STL-009',
            'full_name' => 'Faizal bin Abdullah',
            'short_name' => 'Faizal',
            'designation' => 'Junior Developer',
            'employee_type' => 'full_time',
            'email' => 'faizal.abdullah@stellarindustries.com.my',
            'status' => 'active',
            'employment_start' => '2023-02-01',
            'department_id' => $deptMap['it'] ?? null,
            'supervisor_id' => $itLead?->id,
        ]);

        $this->createEmployee($company, [
            'employee_number' => 'STL-010',
            'full_name' => 'Siti Hajar binti Yusof',
            'short_name' => 'Hajar',
            'designation' => 'HR Executive',
            'employee_type' => 'full_time',
            'email' => 'hajar.yusof@stellarindustries.com.my',
            'status' => 'active',
            'employment_start' => '2022-07-11',
            'department_id' => $deptMap['hr'] ?? null,
            'supervisor_id' => $hrHead?->id,
        ]);

        $this->createEmployee($company, [
            'employee_number' => 'STL-011',
            'full_name' => 'Chan Wai Kit',
            'designation' => 'Warehouse Assistant',
            'employee_type' => 'part_time',
            'email' => 'waikit.chan@stellarindustries.com.my',
            'status' => 'active',
            'employment_start' => '2024-01-15',
            'department_id' => $deptMap['operations'] ?? null,
            'supervisor_id' => $opsHead?->id,
        ]);

        $this->createEmployee($company, [
            'employee_number' => 'STL-012',
            'full_name' => 'Amirul Hakim bin Razak',
            'short_name' => 'Amirul',
            'designation' => 'Marketing Intern',
            'employee_type' => 'intern',
            'email' => 'amirul.razak@stellarindustries.com.my',
            'status' => 'probation',
            'employment_start' => '2025-11-01',
            'department_id' => $deptMap['marketing'] ?? null,
            'supervisor_id' => $opsHead?->id,
        ]);

        $this->createEmployee($company, [
            'employee_number' => 'STL-013',
            'full_name' => 'Lee Chong Wei',
            'short_name' => 'Chong Wei',
            'designation' => 'Senior Operator',
            'employee_type' => 'full_time',
            'email' => 'chongwei.lee@stellarindustries.com.my',
            'status' => 'terminated',
            'employment_start' => '2019-06-01',
            'employment_end' => '2024-08-31',
            'department_id' => $deptMap['production'] ?? null,
        ]);
    }

    /**
     * Seed employees for Nusantara Trading — smaller team.
     *
     * @param  Company  $company  The Nusantara Trading company
     */
    protected function seedNusantaraEmployees(Company $company): void
    {
        $deptMap = $this->getDepartmentMap($company);

        $md = $this->createEmployee($company, [
            'employee_number' => 'NTC-001',
            'full_name' => 'Tan Boon Kiat',
            'short_name' => 'Boon Kiat',
            'designation' => 'Managing Director',
            'employee_type' => 'full_time',
            'email' => 'boonkiat.tan@nusantaratrading.sg',
            'mobile_number' => '+65 9123 4567',
            'status' => 'active',
            'employment_start' => '2022-04-01',
            'department_id' => $deptMap['exec'] ?? null,
        ]);

        $this->createEmployee($company, [
            'employee_number' => 'NTC-002',
            'full_name' => 'Sarah Chen Li Wen',
            'short_name' => 'Sarah',
            'designation' => 'Operations Manager',
            'employee_type' => 'full_time',
            'email' => 'sarah.chen@nusantaratrading.sg',
            'mobile_number' => '+65 9234 5678',
            'status' => 'active',
            'employment_start' => '2022-06-15',
            'department_id' => $deptMap['operations'] ?? null,
            'supervisor_id' => $md?->id,
        ]);

        $this->createEmployee($company, [
            'employee_number' => 'NTC-003',
            'full_name' => 'Rizwan bin Hassan',
            'short_name' => 'Rizwan',
            'designation' => 'Trade Compliance Officer',
            'employee_type' => 'full_time',
            'email' => 'rizwan.hassan@nusantaratrading.sg',
            'status' => 'active',
            'employment_start' => '2023-01-09',
            'department_id' => $deptMap['legal'] ?? null,
            'supervisor_id' => $md?->id,
        ]);

        $this->createEmployee($company, [
            'employee_number' => 'NTC-004',
            'full_name' => 'Ananya Pillai',
            'designation' => 'Logistics Coordinator',
            'employee_type' => 'contractor',
            'email' => 'ananya.pillai@nusantaratrading.sg',
            'status' => 'active',
            'employment_start' => '2024-03-01',
            'department_id' => $deptMap['operations'] ?? null,
            'supervisor_id' => $md?->id,
        ]);
    }

    /**
     * Seed employees for Borneo Logistics — mid-size operations team.
     *
     * @param  Company  $company  The Borneo Logistics company
     */
    protected function seedBorneoEmployees(Company $company): void
    {
        $deptMap = $this->getDepartmentMap($company);

        $gm = $this->createEmployee($company, [
            'employee_number' => 'BLG-001',
            'full_name' => 'James Ting Siik Kuong',
            'short_name' => 'James',
            'designation' => 'General Manager',
            'employee_type' => 'full_time',
            'email' => 'james.ting@borneologistics.my',
            'mobile_number' => '+60 82-123 4567',
            'status' => 'active',
            'employment_start' => '2018-09-01',
            'department_id' => $deptMap['exec'] ?? null,
        ]);

        $this->createEmployee($company, [
            'employee_number' => 'BLG-002',
            'full_name' => 'Dayang Norsyahirah binti Abang',
            'short_name' => 'Syahirah',
            'designation' => 'Finance Executive',
            'employee_type' => 'full_time',
            'email' => 'syahirah.abang@borneologistics.my',
            'status' => 'active',
            'employment_start' => '2019-02-01',
            'department_id' => $deptMap['finance'] ?? null,
            'supervisor_id' => $gm?->id,
        ]);

        $this->createEmployee($company, [
            'employee_number' => 'BLG-003',
            'full_name' => 'Mohd Azlan bin Samat',
            'short_name' => 'Azlan',
            'designation' => 'Warehouse Supervisor',
            'employee_type' => 'full_time',
            'email' => 'azlan.samat@borneologistics.my',
            'mobile_number' => '+60 82-234 5678',
            'status' => 'active',
            'employment_start' => '2019-05-20',
            'department_id' => $deptMap['operations'] ?? null,
            'supervisor_id' => $gm?->id,
        ]);

        $this->createEmployee($company, [
            'employee_number' => 'BLG-004',
            'full_name' => 'Jee Chin Huat',
            'short_name' => 'Chin Huat',
            'designation' => 'Customs Broker',
            'employee_type' => 'full_time',
            'email' => 'chinhuat.jee@borneologistics.my',
            'status' => 'active',
            'employment_start' => '2020-11-02',
            'department_id' => $deptMap['operations'] ?? null,
            'supervisor_id' => $gm?->id,
        ]);

        $this->createEmployee($company, [
            'employee_number' => 'BLG-005',
            'full_name' => 'Siti Rahmah binti Mohamad',
            'short_name' => 'Rahmah',
            'designation' => 'Customer Service Officer',
            'employee_type' => 'full_time',
            'email' => 'rahmah.mohamad@borneologistics.my',
            'status' => 'inactive',
            'employment_start' => '2021-03-01',
            'department_id' => $deptMap['customer_support'] ?? null,
            'supervisor_id' => $gm?->id,
        ]);
    }

    /**
     * Build a department type code → department ID map for a company.
     *
     * @param  Company  $company  The company to map departments for
     * @return array<string, int>
     */
    protected function getDepartmentMap(Company $company): array
    {
        return Department::query()
            ->where('company_id', $company->id)
            ->with('type')
            ->get()
            ->mapWithKeys(fn (Department $dept) => [$dept->type->code => $dept->id])
            ->toArray();
    }

    /**
     * Create an employee using firstOrCreate for idempotency.
     *
     * @param  Company  $company  The company to create the employee for
     * @param  array<string, mixed>  $attributes  Employee attributes
     */
    protected function createEmployee(Company $company, array $attributes): ?Employee
    {
        $employeeNumber = $attributes['employee_number'];
        unset($attributes['employee_number']);

        return Employee::query()->firstOrCreate(
            [
                'company_id' => $company->id,
                'employee_number' => $employeeNumber,
            ],
            $attributes
        );
    }
}
