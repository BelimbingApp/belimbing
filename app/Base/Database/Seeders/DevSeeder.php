<?php

namespace App\Base\Database\Seeders;

use App\Base\Database\Exceptions\DevSeederProductionEnvironmentException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Company\Services\PrimaryCompanyManager;
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
     * Guards against production, then delegates to seed() under the
     * platform operator's tenant context — framework primitives have
     * provisioned the operator and its primary company by the time dev
     * seeders run, but nothing has resolved a tenant context for the CLI
     * execution itself, and seed() commonly creates tenant-owned records.
     */
    public function run(): void
    {
        $this->guardAgainstProduction();

        app(TenantContext::class)->runForTenant(
            $this->operatorPrimaryCompany()?->tenant_id,
            fn (): mixed => $this->seed(),
        );
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
    protected function operatorPrimaryCompany(): ?Company
    {
        return app(PrimaryCompanyManager::class)->platformOperatorCompany();
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
