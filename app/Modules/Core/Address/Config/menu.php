<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'admin.address',
            'label' => 'Addresses',
            'icon' => 'heroicon-o-map-pin',
            'route' => 'admin.addresses.index',
            'permission' => 'admin.address.list',
            'parent' => 'admin',
        ],
    ],
];
