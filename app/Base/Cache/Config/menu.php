<?php
return [
    'items' => [
        [
            'id' => 'admin.system.cache',
            'label' => 'Cache',
            'icon' => 'heroicon-o-bolt',
            'route' => 'admin.system.cache.index',
            'permission' => 'admin.system.cache.view',
            'parent' => 'admin.system.diagnostics',
        ],
    ],
];
