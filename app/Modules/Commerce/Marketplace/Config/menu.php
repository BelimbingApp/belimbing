<?php
return [
    'items' => [
        [
            'id' => 'commerce.marketplace',
            'label' => 'Marketplace',
            'icon' => 'heroicon-o-globe-alt',
            'parent' => 'commerce',
        ],
        [
            'id' => 'commerce.marketplace.ebay',
            'label' => 'eBay',
            'icon' => 'heroicon-o-shopping-cart',
            'route' => 'commerce.marketplace.ebay.index',
            'permission' => 'commerce.marketplace.list',
            'parent' => 'commerce.marketplace',
        ],
        [
            'id' => 'commerce.marketplace.ebay-setting',
            'label' => 'eBay Settings',
            'icon' => 'heroicon-o-cog-6-tooth',
            'route' => 'commerce.marketplace.ebay.settings',
            'permission' => 'commerce.marketplace.manage',
            'parent' => 'commerce.marketplace',
        ],
    ],
];
