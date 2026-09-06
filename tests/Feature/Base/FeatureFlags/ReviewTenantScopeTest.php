<?php

use App\Base\FeatureFlags\Models\FeatureFlagOverride;
use App\Base\FeatureFlags\Services\FeatureFlagDeclarationInventory;
use App\Base\FeatureFlags\Services\FeatureFlagDefinition;
use App\Base\FeatureFlags\Services\FeatureFlagRegistry;
use App\Base\Tenancy\Contracts\TenantContext;

it('does not pin the declared-flag inventory to the first tenant in a worker', function (): void {
    app(FeatureFlagRegistry::class)->replace([
        new FeatureFlagDefinition('review.tenant', true, 'base/review'),
    ]);

    $firstTenant = createTenant(['name' => 'First review tenant']);
    $secondTenant = createTenant(['name' => 'Second review tenant']);
    FeatureFlagOverride::query()->create([
        'tenant_id' => $firstTenant->id,
        'flag' => 'review.tenant',
        'enabled' => false,
    ]);
    FeatureFlagOverride::query()->create([
        'tenant_id' => $secondTenant->id,
        'flag' => 'review.tenant',
        'enabled' => true,
    ]);

    app(TenantContext::class)->set($firstTenant->id);
    $inventory = app(FeatureFlagDeclarationInventory::class);
    expect($inventory->forCurrentTenant()[0]['override_enabled'])->toBeFalse();

    app()->forgetScopedInstances();
    app(TenantContext::class)->set($secondTenant->id);
    expect(app(FeatureFlagDeclarationInventory::class)->forCurrentTenant()[0]['override_enabled'])->toBeTrue();
});
