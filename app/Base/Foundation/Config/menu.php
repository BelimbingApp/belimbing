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
    ],
];
