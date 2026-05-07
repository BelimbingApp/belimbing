<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'admin.addresses',
            'label' => 'Addresses',
            'icon' => 'heroicon-o-map-pin',
            'route' => 'admin.addresses.index',
            'permission' => 'core.address.list',
            'parent' => 'admin',
        ],
    ],
];
