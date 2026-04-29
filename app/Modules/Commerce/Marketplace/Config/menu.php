<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'commerce.marketplace',
            'label' => 'Marketplace',
            'icon' => 'heroicon-o-globe-alt',
            'route' => 'commerce.marketplace.ebay.index',
            'permission' => 'commerce.marketplace.list',
            'parent' => 'commerce',
            'position' => 20,
        ],
        [
            'id' => 'commerce.marketplace.ebay_settings',
            'label' => 'eBay Settings',
            'icon' => 'heroicon-o-cog-6-tooth',
            'route' => 'commerce.marketplace.ebay.settings',
            'permission' => 'commerce.marketplace.manage',
            'parent' => 'commerce.marketplace',
            'position' => 25,
        ],
    ],
];
