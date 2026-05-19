<?php

return [
    'items' => [
        [
            'id' => 'commerce.inventory',
            'label' => 'Inventory',
            'icon' => 'heroicon-o-queue-list',
            'route' => 'commerce.inventory.items.index',
            'permission' => 'commerce.inventory.item.list',
            'parent' => 'commerce',
        ],
    ],
];
