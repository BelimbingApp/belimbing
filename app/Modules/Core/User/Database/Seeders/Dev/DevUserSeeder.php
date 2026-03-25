<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\User\Database\Seeders\Dev;

use App\Base\Database\Seeders\DevSeeder;
use App\Modules\Core\Company\Database\Seeders\Dev\DevCompanyAddressSeeder;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Database\Seeders\Dev\DevEmployeeSeeder;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;

class DevUserSeeder extends DevSeeder
{
    protected const COMPANY_STELLAR = 'Stellar Industries Sdn Bhd';

    protected array $dependencies = [
        DevCompanyAddressSeeder::class,
        DevEmployeeSeeder::class,
    ];

    /**
     * Creates realistic dev users across seeded companies.
     * Each user gets password 'password' for easy local login.
     * Idempotent via firstOrCreate on email.
     */
    protected function seed(): void
    {
        $companies = Company::query()->orderBy('id')->get();

        if ($companies->isEmpty()) {
            return;
        }

        foreach ($this->users() as $definition) {
            $company = $companies->firstWhere('name', $definition['company']);

            if ($company === null) {
                continue;
            }

            $user = User::query()->firstOrCreate(
                ['email' => $definition['email']],
                [
                    'company_id' => $company->id,
                    'name' => $definition['name'],
                    'password' => 'password',
                    'email_verified_at' => now(),
                ]
            );

            $employee = Employee::query()
                ->where('email', $definition['email'])
                ->where('company_id', $company->id)
                ->first();

            if ($employee) {
                $user->update(['employee_id' => $employee->id]);
            }
        }
    }

    /**
     * Dev user definitions mapped to company names from DevCompanyAddressSeeder.
     *
     * @return array<int, array{name: string, email: string, company: string}>
     */
    private function users(): array
    {
        return [
            // Stellar Industries — three users (linked to employees in DevEmployeeSeeder)
            [
                'name' => 'Lim Wei Jie',
                'email' => 'weijie.lim@stellarindustries.com.my',
                'company' => self::COMPANY_STELLAR,
            ],
            [
                'name' => 'Tan Siew Mei',
                'email' => 'siewmei.tan@stellarindustries.com.my',
                'company' => self::COMPANY_STELLAR,
            ],
            [
                'name' => 'Ahmad bin Ismail',
                'email' => 'ahmad.ismail@stellarindustries.com.my',
                'company' => self::COMPANY_STELLAR,
            ],

            // Nusantara Trading — one user
            [
                'name' => 'Tan Boon Kiat',
                'email' => 'boonkiat.tan@nusantaratrading.sg',
                'company' => 'Nusantara Trading Co',
            ],

            // Borneo Logistics — one user
            [
                'name' => 'Ahmad Razak',
                'email' => 'razak.ahmad@borneologistics.my',
                'company' => 'Borneo Logistics',
            ],
        ];
    }
}
