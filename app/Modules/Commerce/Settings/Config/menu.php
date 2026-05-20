<?php

return [
    'items' => [
        [
            'id' => 'commerce.settings',
            'label' => 'Settings',
            'icon' => 'heroicon-o-cog-6-tooth',
            'parent' => 'commerce',
        ],
        [
            'id' => 'commerce.settings.general',
            'label' => 'General',
            'icon' => 'heroicon-o-adjustments-horizontal',
            'route' => 'commerce.settings',
            'permission' => 'commerce.inventory.manage',
            'parent' => 'commerce.settings',
        ],
    ],
];
