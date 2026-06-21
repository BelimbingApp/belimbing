<?php

return [
    'items' => [
        [
            'id' => 'admin.system.bundles',
            'label' => 'Bundles',
            'icon' => 'heroicon-o-puzzle-piece',
            'route' => 'admin.system.bundles.index',
            'permission' => 'admin.system.bundles.view',
            'parent' => 'admin.system',
        ],
        [
            'id' => 'admin.system.update.business-domain',
            'label' => 'Business Domains',
            'icon' => 'heroicon-o-squares-2x2',
            'route' => 'admin.system.update.business-domains.index',
            'permission' => 'admin.system.update.business-domain.view',
            'parent' => 'admin.system.update',
        ],
    ],
];
