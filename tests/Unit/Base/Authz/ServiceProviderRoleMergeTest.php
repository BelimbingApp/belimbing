<?php

use App\Base\Authz\ServiceProvider;
use Tests\TestCase;

uses(TestCase::class);

it('merges module role capabilities without replacing platform role metadata', function (): void {
    $provider = new ServiceProvider(app());
    $method = new ReflectionMethod($provider, 'mergeRoleDefinition');
    $method->setAccessible(true);

    $merged = $method->invoke($provider, [
        'name' => 'Tenant Owner',
        'description' => 'Platform-owned baseline role.',
        'capabilities' => [
            'admin.company.view',
            'commerce.catalog.view',
        ],
    ], [
        'name' => 'Commerce Tenant Owner',
        'description' => 'Module contribution.',
        'capabilities' => [
            'commerce.catalog.view',
            'commerce.inventory.manage',
        ],
    ]);

    expect($merged)->toMatchArray([
        'name' => 'Tenant Owner',
        'description' => 'Platform-owned baseline role.',
        'capabilities' => [
            'admin.company.view',
            'commerce.catalog.view',
            'commerce.inventory.manage',
        ],
    ]);
});
