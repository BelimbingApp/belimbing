<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'system.settings',
            'label' => 'Settings',
            'icon' => 'heroicon-o-cog-6-tooth',
            'route' => 'admin.settings.index',
            'permission' => 'admin.settings.manage',
            'parent' => 'system',
            'position' => 94,
        ],
    ],
];
