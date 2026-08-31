<?php

use App\Base\Database\Seeders\DevSeeder;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;

/**
 * A concrete dev seeder that needs a tenant context to run — mirrors the
 * real failure: DevCompanyAddressSeeder creates a Company without an
 * explicit tenant_id, relying on Company's creating hook to pull it from
 * TenantContext::requireTenantId().
 */
class ProbeDevSeeder extends DevSeeder
{
    public ?int $observedTenantId = null;

    protected function seed(): void
    {
        $this->observedTenantId = app(TenantContext::class)->requireTenantId();

        Company::factory()->create();
    }
}

beforeEach(function (): void {
    $this->app['env'] = 'local';
});

it('runs a dev seeder under the operator tenant context even with none ambient', function (): void {
    $operatorCompany = provisionPlatformOperatorCompany();
    app(TenantContext::class)->clear();

    expect(app(TenantContext::class)->hasTenant())->toBeFalse();

    $seeder = app(ProbeDevSeeder::class);
    $seeder->run();

    expect($seeder->observedTenantId)->toBe((int) $operatorCompany->tenant_id);
});

it('restores the prior context after a dev seeder runs', function (): void {
    provisionPlatformOperatorCompany();
    app(TenantContext::class)->set(999999);

    app(ProbeDevSeeder::class)->run();

    expect(app(TenantContext::class)->currentTenantId())->toBe(999999);
});

it('restores context when the dev seeder throws', function (): void {
    provisionPlatformOperatorCompany();
    app(TenantContext::class)->clear();

    $seeder = new class extends DevSeeder
    {
        protected function seed(): void
        {
            throw new RuntimeException('boom');
        }
    };

    try {
        $seeder->run();
    } catch (RuntimeException) {
        // expected
    }

    expect(app(TenantContext::class)->hasTenant())->toBeFalse();
});
