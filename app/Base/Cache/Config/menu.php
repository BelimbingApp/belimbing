<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'system.cache',
            'label' => 'Cache',
            'icon' => 'heroicon-o-bolt',
            'route' => 'admin.system.cache.index',
            'permission' => 'admin.system_cache.view',
            'parent' => 'system',
        ],
    ],
];
