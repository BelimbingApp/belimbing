<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\DTO\TenantResolution;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\Route;

it('records the resolver that established tenant context for a request', function (): void {
    Route::middleware('web')->get('/zz-tenant-resolution', function () {
        $resolution = app(TenantContext::class)->resolution();

        return response()->json([
            'resolver' => $resolution?->resolver,
            'tenant_id' => $resolution?->tenantId,
        ]);
    });

    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    $this->actingAs($user)
        ->getJson('/zz-tenant-resolution')
        ->assertOk()
        ->assertExactJson([
            'resolver' => 'session',
            'tenant_id' => $company->tenant_id,
        ]);
});

it('clears request resolution and restores it across an explicit tenant run', function (): void {
    $context = app(TenantContext::class);
    $resolution = new TenantResolution(TenantResolution::HOST, 17);

    $context->setResolution($resolution);

    $observed = $context->runForTenant(
        29,
        fn (): array => [$context->currentTenantId(), $context->resolution()],
    );

    expect($observed)->toBe([29, null])
        ->and($context->resolution())->toBe($resolution);

    $context->clear();

    expect($context->resolution())->toBeNull();
});
