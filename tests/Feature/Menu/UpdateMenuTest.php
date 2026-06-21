<?php

use App\Base\Menu\Services\MenuDiscoveryService;

test('update menu groups updates, business domains, and github access', function (): void {
    $items = app(MenuDiscoveryService::class)->discover()->keyBy('id');

    expect($items->get('admin.system.software.deployment'))
        ->toMatchArray([
            'label' => 'Updates',
            'parent' => 'admin.system.software',
            'route' => 'admin.system.software.deployment.index',
            'permission' => 'admin.system.software.deployment.manage',
        ])
        ->and($items->get('admin.system.software.business-domain'))
        ->toMatchArray([
            'label' => 'Business Domains',
            'parent' => 'admin.system.software',
            'route' => 'admin.system.software.business-domains.index',
            'permission' => 'admin.system.software.business-domain.view',
        ])
        ->and($items->get('admin.system.software.github-access'))
        ->toMatchArray([
            'label' => 'GitHub Access',
            'parent' => 'admin.system.software',
        ])
        ->and($items->has('admin.system.domains'))->toBeFalse()
        ->and($items->has('admin.system.software.belimbing'))->toBeFalse();
});
