<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Seeders;

use App\Base\Database\Exceptions\DevSeederProductionEnvironmentException;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Seeder;

abstract class DevSeeder extends Seeder
{
    /**
     * Dev seeders that must run before this one.
     *
     * @var array<int, class-string<DevSeeder>>
     */
    protected array $dependencies = [];

    /**
     * Run the database seeds.
     *
     * Guards against production, then delegates to seed().
     */
    public function run(): void
    {
        $this->guardAgainstProduction();

        $this->seed();
    }

    /**
     * Seed the database (development data).
     *
     * Implement in concrete dev seeders.
     */
    abstract protected function seed(): void;

    /**
     * Resolve the tenant that dev seeders should target by default.
     */
    protected function licenseeCompany(): ?Company
    {
        return Company::query()->find(Company::LICENSEE_ID);
    }

    /**
     * Prevent dev seeders from running in production.
     */
    protected function guardAgainstProduction(): void
    {
        if (! app()->environment('local')) {
            throw DevSeederProductionEnvironmentException::forEnvironment(app()->environment());
        }
    }
}
