<?php

return [
    'items' => [
        [
            'id' => 'admin.system.software.bundles',
            'label' => 'Bundles',
            'icon' => 'heroicon-o-puzzle-piece',
            'route' => 'admin.system.software.bundles.index',
            'permission' => 'admin.system.software.bundles.view',
            'parent' => 'admin.system.software',
        ],
        [
            'id' => 'admin.system.software.business-domain',
            'label' => 'Business Domains',
            'icon' => 'heroicon-o-squares-2x2',
            'route' => 'admin.system.software.business-domains.index',
            'permission' => 'admin.system.software.business-domain.view',
            'parent' => 'admin.system.software',
        ],
    ],
];
