<?php

return [
    'items' => [
        [
            'id' => 'admin.system.plugins',
            'label' => 'Plugins',
            'icon' => 'heroicon-o-puzzle-piece',
            'route' => 'admin.system.plugins.index',
            'permission' => 'admin.system.plugins.view',
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
