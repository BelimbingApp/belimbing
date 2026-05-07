<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'admin.users',
            'label' => 'Users',
            'icon' => 'heroicon-o-users',
            'route' => 'admin.users.index',
            'permission' => 'core.user.list',
            'parent' => 'admin',
        ],
    ],
];
