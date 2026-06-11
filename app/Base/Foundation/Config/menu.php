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
            'id' => 'admin.system.domains',
            'label' => 'Domains',
            'icon' => 'heroicon-o-squares-2x2',
            'route' => 'admin.system.domains.index',
            'permission' => 'admin.system.domains.view',
            'parent' => 'admin.system',
        ],
    ],
];
