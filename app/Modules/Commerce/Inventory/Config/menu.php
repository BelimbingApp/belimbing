<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'commerce',
            'label' => 'Commerce',
            'icon' => 'heroicon-o-shopping-bag',
        ],
        [
            'id' => 'commerce.inventory.items',
            'label' => 'Inventory Workbench',
            'icon' => 'heroicon-o-queue-list',
            'route' => 'commerce.inventory.items.index',
            'permission' => 'commerce.inventory_item.list',
            'parent' => 'commerce',
        ],
    ],
];
