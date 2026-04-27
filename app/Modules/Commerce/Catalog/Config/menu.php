<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'commerce.catalog',
            'label' => 'Catalog Workbench',
            'icon' => 'heroicon-o-rectangle-stack',
            'route' => 'commerce.catalog.index',
            'permission' => 'commerce.catalog.view',
            'parent' => 'commerce',
        ],
    ],
];
