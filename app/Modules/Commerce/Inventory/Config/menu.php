<?php
return [
    'items' => [
        [
            'id' => 'commerce.inventory',
            'label' => 'Inventory',
            'icon' => 'heroicon-o-queue-list',
            'parent' => 'commerce',
        ],
        [
            'id' => 'commerce.inventory.item',
            'label' => 'Inventory Workbench',
            'icon' => 'heroicon-o-queue-list',
            'route' => 'commerce.inventory.items.index',
            'permission' => 'commerce.inventory.item.list',
            'parent' => 'commerce.inventory',
        ],
        [
            'id' => 'commerce.inventory.setting',
            'label' => 'Settings',
            'icon' => 'heroicon-o-cog-6-tooth',
            'route' => 'commerce.inventory.settings',
            'permission' => 'commerce.inventory.manage',
            'parent' => 'commerce.inventory',
        ],
    ],
];
