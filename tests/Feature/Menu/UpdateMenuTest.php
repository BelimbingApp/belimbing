<?php

use App\Base\Menu\Services\MenuDiscoveryService;

test('software menu groups domains, updates, and github access', function (): void {
    $items = app(MenuDiscoveryService::class)->discover()->keyBy('id');

    expect($items->get('admin.system.software.domains'))
        ->toMatchArray([
            'label' => 'Domains',
            'parent' => 'admin.system.software',
            'route' => 'admin.system.software.domains.index',
            'permission' => 'admin.system.software.domains.view',
        ])
        ->and($items->get('admin.system.software.updates'))
        ->toMatchArray([
            'label' => 'Updates',
            'parent' => 'admin.system.software',
            'route' => 'admin.system.software.updates.index',
            'permission' => 'admin.system.software.updates.manage',
        ])
        ->and($items->get('admin.system.software.github-access'))
        ->toMatchArray([
            'label' => 'GitHub Access',
            'parent' => 'admin.system.software',
        ])
        ->and($items->has('admin.system.software.bundles'))->toBeFalse()
        ->and($items->has('admin.system.software.business-domain'))->toBeFalse();
});
