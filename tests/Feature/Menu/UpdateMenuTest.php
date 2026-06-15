<?php

use App\Base\Menu\Services\MenuDiscoveryService;

test('update menu groups deployment, business domains, and github access', function (): void {
    $items = app(MenuDiscoveryService::class)->discover()->keyBy('id');

    expect($items->get('admin.system.update.deployment'))
        ->toMatchArray([
            'label' => 'Deployment',
            'parent' => 'admin.system.update',
            'route' => 'admin.system.update.deployment.index',
            'permission' => 'admin.system.update.deployment.manage',
        ])
        ->and($items->get('admin.system.update.business-domain'))
        ->toMatchArray([
            'label' => 'Business Domains',
            'parent' => 'admin.system.update',
            'route' => 'admin.system.update.business-domains.index',
            'permission' => 'admin.system.update.business-domain.view',
        ])
        ->and($items->get('admin.system.update.github-access'))
        ->toMatchArray([
            'label' => 'GitHub Access',
            'parent' => 'admin.system.update',
        ])
        ->and($items->has('admin.system.domains'))->toBeFalse()
        ->and($items->has('admin.system.update.belimbing'))->toBeFalse();
});
