<?php

return [
    'items' => [
        [
            'id' => 'commerce.catalog',
            'label' => 'Catalog',
            'icon' => 'heroicon-o-rectangle-stack',
            'permission' => 'commerce.catalog.view',
            'parent' => 'commerce',
        ],
        [
            'id' => 'commerce.catalog.categories',
            'label' => 'Categories',
            'icon' => 'heroicon-o-folder',
            'route' => 'commerce.catalog.categories',
            'permission' => 'commerce.catalog.view',
            'parent' => 'commerce.catalog',
        ],
        [
            'id' => 'commerce.catalog.templates',
            'label' => 'Templates',
            'icon' => 'heroicon-o-clipboard-document-list',
            'route' => 'commerce.catalog.templates',
            'permission' => 'commerce.catalog.view',
            'parent' => 'commerce.catalog',
        ],
        [
            'id' => 'commerce.catalog.attributes',
            'label' => 'Attributes',
            'icon' => 'heroicon-o-tag',
            'route' => 'commerce.catalog.attributes',
            'permission' => 'commerce.catalog.view',
            'parent' => 'commerce.catalog',
        ],
    ],
];
